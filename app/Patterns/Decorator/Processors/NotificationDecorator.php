<?php

namespace App\Patterns\Decorator\Processors;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Account;
use App\Notifications\TransactionProcessed;
use App\Notifications\TransactionFailed;
use App\Notifications\LargeTransactionAlert;
use App\Exceptions\ProcessorException;
use App\Patterns\Decorator\Interfaces\TransactionProcessor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;

class NotificationDecorator implements TransactionProcessor
{
    private TransactionProcessor $processor;
    private bool $enabled = true;
    private array $notificationSettings = [
        'large_transaction_threshold' => 10000.00,
        'notify_on_completion' => true,
        'notify_on_failure' => true,
        'notify_admins_on_large_transactions' => true
    ];

    public function __construct(TransactionProcessor $processor)
    {
        $this->processor = $processor;
        $this->enabled = config('banking.processors.notifications.enabled', true);
        $this->notificationSettings = config('banking.notifications', $this->notificationSettings);
    }

    public function process(Transaction $transaction): bool
    {
        if (!$this->isEnabled()) {
            return $this->processor->process($transaction);
        }

        try {
            Log::debug('NotificationDecorator: Processing transaction with notifications', [
                'transaction_id' => $transaction->id,
                'type' => $transaction->type->value
            ]);

            // Process the transaction first
            $result = $this->processor->process($transaction);

            // Send notifications based on result
            if ($result) {
                $this->sendSuccessNotifications($transaction);
            } else {
                $this->sendFailureNotifications($transaction);
            }

            // Send large transaction notifications if applicable
            if ($transaction->amount >= $this->notificationSettings['large_transaction_threshold']) {
                $this->sendLargeTransactionNotifications($transaction);
            }

            Log::info('NotificationDecorator: Notifications sent successfully', [
                'transaction_id' => $transaction->id,
                'result' => $result
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('NotificationDecorator: Error during notification processing', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);

            // Don't rethrow - notifications should not block transaction processing
            return $this->processor->process($transaction);
        }
    }

    private function sendSuccessNotifications(Transaction $transaction): void
    {
        if (!$this->notificationSettings['notify_on_completion']) {
            return;
        }

        $accounts = $this->getAccountsToNotify($transaction);

        foreach ($accounts as $account) {
            $users = $account->users()->where('email_notifications', true)->get();

            foreach ($users as $user) {
                $user->notify(new TransactionProcessed($transaction, $account));
            }
        }
    }

    private function sendFailureNotifications(Transaction $transaction): void
    {
        if (!$this->notificationSettings['notify_on_failure']) {
            return;
        }

        $accounts = $this->getAccountsToNotify($transaction);

        foreach ($accounts as $account) {
            $users = $account->users()->where('email_notifications', true)->get();

            foreach ($users as $user) {
                $user->notify(new TransactionFailed($transaction, $account));
            }
        }
    }

    private function sendLargeTransactionNotifications(Transaction $transaction): void
    {
        if (!$this->notificationSettings['notify_admins_on_large_transactions']) {
            return;
        }

        Log::info('NotificationDecorator: Large transaction detected', [
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
            'threshold' => $this->notificationSettings['large_transaction_threshold']
        ]);

        $admins = User::role(['admin', 'manager', 'risk_manager'])->get();

        foreach ($admins as $admin) {
            $admin->notify(new LargeTransactionAlert($transaction));
        }
    }

    private function getAccountsToNotify(Transaction $transaction): array
    {
        $accounts = [];

        if ($transaction->from_account_id) {
            $accounts[] = Account::findOrFail($transaction->from_account_id);
        }

        if ($transaction->to_account_id) {
            $toAccount = Account::findOrFail($transaction->to_account_id);
            if (!in_array($toAccount->id, array_column($accounts, 'id'))) {
                $accounts[] = $toAccount;
            }
        }

        return $accounts;
    }

    public function validate(Transaction $transaction): bool
    {
        // Notifications don't perform validation
        return $this->processor->validate($transaction);
    }

    public function getName(): string
    {
        return 'NotificationDecorator';
    }

    public function getMetadata(): array
    {
        return [
            'name' => $this->getName(),
            'enabled' => $this->isEnabled(),
            'notification_settings' => $this->notificationSettings
        ];
    }

    public function setContext(array $context): self
    {
        $this->processor->setContext($context);
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && config('services.notifications.enabled', true);
    }
}
