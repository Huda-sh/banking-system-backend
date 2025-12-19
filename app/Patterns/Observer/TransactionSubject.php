<?php

namespace App\Patterns\Observer;

use SplSubject;
use SplObserver;
use SplObjectStorage;
use App\Models\Transaction;
use App\Patterns\Observer\Interfaces\TransactionObserver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use App\Exceptions\ObserverException;

class TransactionSubject implements SplSubject
{
    private SplObjectStorage $observers;
    private ?Transaction $transaction = null;
    private array $eventMap = [
        'created' => 'onTransactionCreated',
        'completed' => 'onTransactionCompleted',
        'failed' => 'onTransactionFailed',
        'approved' => 'onTransactionApproved',
        'reversed' => 'onTransactionReversed',
        'scheduled_executed' => 'onScheduledTransactionExecuted'
    ];

    public function __construct()
    {
        $this->observers = new SplObjectStorage();
    }

    public function attach(SplObserver $observer): void
    {
        if (!$observer instanceof TransactionObserver) {
            throw new ObserverException("Observer must implement TransactionObserver interface");
        }

        $this->observers->attach($observer);
        Log::debug('TransactionSubject: Observer attached', [
            'observer' => $observer->getName(),
            'priority' => $observer->getPriority()
        ]);
    }

    public function detach(SplObserver $observer): void
    {
        $this->observers->detach($observer);
        Log::debug('TransactionSubject: Observer detached', [
            'observer' => $observer->getName()
        ]);
    }

    public function notify(): void
    {
        if (!$this->transaction) {
            throw new ObserverException("No transaction set for notification");
        }

        $event = $this->getEventFromTransaction();

        if (!array_key_exists($event, $this->eventMap)) {
            Log::warning('TransactionSubject: Unknown event type', [
                'event' => $event,
                'transaction_id' => $this->transaction->id,
                'status' => $this->transaction->status->value
            ]);
            return;
        }

        $method = $this->eventMap[$event];

        Log::debug('TransactionSubject: Notifying observers', [
            'event' => $event,
            'method' => $method,
            'transaction_id' => $this->transaction->id,
            'observer_count' => count($this->observers)
        ]);

        // Sort observers by priority
        $sortedObservers = $this->getSortedObservers();

        foreach ($sortedObservers as $observer) {
            $this->notifyObserver($observer, $method);
        }
    }

    private function getEventFromTransaction(): string
    {
        return match(true) {
            $this->transaction->wasRecentlyCreated => 'created',
            $this->transaction->status === 'completed' && $this->transaction->wasChanged('status') => 'completed',
            $this->transaction->status === 'failed' && $this->transaction->wasChanged('status') => 'failed',
            $this->transaction->status === 'approved' && $this->transaction->wasChanged('status') => 'approved',
            $this->transaction->status === 'reversed' && $this->transaction->wasChanged('status') => 'reversed',
            $this->transaction->scheduledTransaction && $this->transaction->wasRecentlyCreated => 'scheduled_executed',
            default => 'completed' // Fallback to completed for safety
        };
    }

    private function getSortedObservers(): array
    {
        $observers = [];

        foreach ($this->observers as $observer) {
            $observers[] = $observer;
        }

        usort($observers, function ($a, $b) {
            return $a->getPriority() <=> $b->getPriority();
        });

        return $observers;
    }

    private function notifyObserver(TransactionObserver $observer, string $method): void
    {
        if (!$observer->isEnabled()) {
            Log::debug('TransactionSubject: Observer disabled, skipping', [
                'observer' => $observer->getName(),
                'method' => $method
            ]);
            return;
        }

        try {
            $startTime = microtime(true);

            $observer->$method($this->transaction);

            $executionTime = microtime(true) - $startTime;

            Log::debug('TransactionSubject: Observer notified successfully', [
                'observer' => $observer->getName(),
                'method' => $method,
                'execution_time' => round($executionTime, 4),
                'transaction_id' => $this->transaction->id
            ]);

        } catch (\Exception $e) {
            Log::error('TransactionSubject: Observer notification failed', [
                'observer' => $observer->getName(),
                'method' => $method,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'transaction_id' => $this->transaction->id,
                'trace' => $e->getTraceAsString()
            ]);

            // Don't rethrow - continue with other observers
            // But log the error for monitoring
        }
    }

    public function setTransaction(Transaction $transaction): void
    {
        $this->transaction = $transaction;
    }

    public function getTransaction(): Transaction
    {
        if (!$this->transaction) {
            throw new ObserverException("No transaction set");
        }

        return $this->transaction;
    }

    public function attachDefaultObservers(): void
    {
        $observers = config('banking.observers.default', [
            \App\Patterns\Observer\Observers\AuditLogObserver::class,
            \App\Patterns\Observer\Observers\BalanceUpdateObserver::class,
            \App\Patterns\Observer\Observers\EmailNotificationObserver::class,
            \App\Patterns\Observer\Observers\SMSPNotificationObserver::class
        ]);

        foreach ($observers as $observerClass) {
            if (class_exists($observerClass)) {
                $observer = app($observerClass);
                $this->attach($observer);
            } else {
                Log::warning('TransactionSubject: Observer class not found', [
                    'class' => $observerClass
                ]);
            }
        }
    }

    public function attachObserversByType(array $observerTypes): void
    {
        $observerMap = [
            'audit' => \App\Patterns\Observer\Observers\AuditLogObserver::class,
            'balance' => \App\Patterns\Observer\Observers\BalanceUpdateObserver::class,
            'email' => \App\Patterns\Observer\Observers\EmailNotificationObserver::class,
            'sms' => \App\Patterns\Observer\Observers\SMSPNotificationObserver::class
        ];

        foreach ($observerTypes as $type) {
            if (array_key_exists($type, $observerMap) && class_exists($observerMap[$type])) {
                $observer = app($observerMap[$type]);
                $this->attach($observer);
            }
        }
    }

    public function getObserverStats(): array
    {
        $stats = [
            'total_observers' => count($this->observers),
            'enabled_observers' => 0,
            'observer_details' => []
        ];

        foreach ($this->observers as $observer) {
            $stats['observer_details'][] = [
                'name' => $observer->getName(),
                'priority' => $observer->getPriority(),
                'enabled' => $observer->isEnabled()
            ];

            if ($observer->isEnabled()) {
                $stats['enabled_observers']++;
            }
        }

        return $stats;
    }

    public function clearObservers(): void
    {
        $this->observers = new SplObjectStorage();
        Log::debug('TransactionSubject: All observers cleared');
    }

    public function hasObservers(): bool
    {
        return count($this->observers) > 0;
    }
}
