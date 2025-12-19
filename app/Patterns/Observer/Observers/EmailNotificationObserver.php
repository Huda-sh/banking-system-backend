<?php

namespace App\Patterns\Observer\Observers;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Account;
use App\Notifications\TransactionCompleted;
use App\Notifications\TransactionFailed;
use App\Notifications\TransactionApproved;
use App\Notifications\LargeTransactionAlert;
use App\Notifications\ScheduledTransactionExecuted;
use App\Patterns\Observer\Interfaces\TransactionObserver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Exceptions\ObserverException;

class EmailNotificationObserver implements TransactionObserver
{
    private int $priority = 50;
    private bool $enabled = true;

    public function __construct()
    {
        $this->enabled = config('services.email_notifications.enabled', true);
    }

    public function onTransactionCreated(Transaction $transaction): void
    {
        // No email notification needed for creation, handled on completion/failure
    }

    public function onTransactionCompleted(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            Log::debug('EmailNotificationObserver: Sending completion notification', [
                'transaction_id' => $transaction->id,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency
            ]);

            $this->notifyAccountHolders($transaction, 'completed');

            // Send large transaction alert if applicable
            $this->sendLargeTransactionAlert($transaction);

        } catch (\Exception $e) {
            Log::error('EmailNotificationObserver: Failed to send completion notification', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Email notification failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onTransactionFailed(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            Log::debug('EmailNotificationObserver: Sending failure notification', [
                'transaction_id' => $transaction->id,
                'error' => $transaction->metadata['error'] ?? 'Unknown error'
            ]);

            $this->notifyAccountHolders($transaction, 'failed');

        } catch (\Exception $e) {
            Log::error('EmailNotificationObserver: Failed to send failure notification', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Email notification failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onTransactionApproved(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            Log::debug('EmailNotificationObserver: Sending approval notification', [
                'transaction_id' => $transaction->id,
                'approved_by' => $transaction->approved_by
            ]);

            $this->notifyAccountHolders($transaction, 'approved');

        } catch (\Exception $e) {
            Log::error('EmailNotificationObserver: Failed to send approval notification', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Email notification failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onTransactionReversed(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            Log::debug('EmailNotificationObserver: Sending reversal notification', [
                'transaction_id' => $transaction->id,
                'reversed_by' => $transaction->metadata['reversed_by'] ?? 'system'
            ]);

            $this->notifyAccountHolders($transaction, 'reversed');

        } catch (\Exception $e) {
            Log::error('EmailNotificationObserver: Failed to send reversal notification', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Email notification failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onScheduledTransactionExecuted(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            Log::debug('EmailNotificationObserver: Sending scheduled execution notification', [
                'transaction_id' => $transaction->id,
                'scheduled_id' => $transaction->scheduledTransaction->id ?? null
            ]);

            $this->notifyAccountHolders($transaction, 'scheduled_executed');

        } catch (\Exception $e) {
            Log::error('EmailNotificationObserver: Failed to send scheduled execution notification', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Email notification failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function notifyAccountHolders(Transaction $transaction, string $type): void
    {
        $accounts = $this->getAccountsToNotify($transaction);

        foreach ($accounts as $account) {
            $users = $account->users()->where('email_notifications', true)->get();

            foreach ($users as $user) {
                $this->sendNotificationToUser($user, $transaction, $type, $account);
            }
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

    private function sendNotificationToUser(User $user, Transaction $transaction, string $type, Account $account): void
    {
        $notification = match($type) {
            'completed' => new TransactionCompleted($transaction, $account),
            'failed' => new TransactionFailed($transaction, $account),
            'approved' => new TransactionApproved($transaction, $account),
            'reversed' => new TransactionReversed($transaction, $account),
            'scheduled_executed' => new ScheduledTransactionExecuted($transaction, $account),
            default => new TransactionCompleted($transaction, $account)
        };

        $user->notify($notification);
    }

    private function sendLargeTransactionAlert(Transaction $transaction): void
    {
        $threshold = config('banking.large_transaction_threshold', 10000.00);

        if ($transaction->amount >= $threshold) {
            Log::info('EmailNotificationObserver: Large transaction detected', [
                'transaction_id' => $transaction->id,
                'amount' => $transaction->amount,
                'threshold' => $threshold
            ]);

            $admins = User::role(['admin', 'manager', 'risk_manager'])->get();

            foreach ($admins as $admin) {
                $admin->notify(new LargeTransactionAlert($transaction));
            }
        }
    }

    public function getName(): string
    {
        return 'EmailNotificationObserver';
    }

    public function isEnabled(): bool
    {
        return $this->enabled && config('mail.enabled', true);
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }
}
