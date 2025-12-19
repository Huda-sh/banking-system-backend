<?php

namespace App\Patterns\Observer\Observers;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Account;
use App\Notifications\SMSTransactionAlert;
use App\Notifications\SMSTransactionFailed;
use App\Notifications\SMSTransactionApproved;
use App\Patterns\Observer\Interfaces\TransactionObserver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Exceptions\ObserverException;
use Carbon\Carbon;

class SMSPNotificationObserver implements TransactionObserver
{
    private int $priority = 60;
    private bool $enabled = true;
    private array $smsSettings;

    public function __construct()
    {
        $this->enabled = config('services.sms_notifications.enabled', true);
        $this->smsSettings = config('banking.sms_notifications', [
            'large_transaction_threshold' => 5000.00,
            'daily_limit' => 10,
            'cooldown_minutes' => 5
        ]);
    }

    public function onTransactionCreated(Transaction $transaction): void
    {
        // No SMS needed for creation
    }

    public function onTransactionCompleted(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            // Only send SMS for large transactions or withdrawals
            if ($this->shouldSendSMSTransaction($transaction)) {
                Log::debug('SMSPNotificationObserver: Sending SMS completion notification', [
                    'transaction_id' => $transaction->id,
                    'amount' => $transaction->amount
                ]);

                $this->notifyAccountHolders($transaction, 'completed');
            }

        } catch (\Exception $e) {
            Log::error('SMSPNotificationObserver: Failed to send SMS completion notification', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("SMS notification failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onTransactionFailed(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            Log::debug('SMSPNotificationObserver: Sending SMS failure notification', [
                'transaction_id' => $transaction->id
            ]);

            $this->notifyAccountHolders($transaction, 'failed');

        } catch (\Exception $e) {
            Log::error('SMSPNotificationObserver: Failed to send SMS failure notification', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("SMS notification failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onTransactionApproved(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            Log::debug('SMSPNotificationObserver: Sending SMS approval notification', [
                'transaction_id' => $transaction->id
            ]);

            $this->notifyAccountHolders($transaction, 'approved');

        } catch (\Exception $e) {
            Log::error('SMSPNotificationObserver: Failed to send SMS approval notification', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("SMS notification failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onTransactionReversed(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            Log::debug('SMSPNotificationObserver: Sending SMS reversal notification', [
                'transaction_id' => $transaction->id
            ]);

            $this->notifyAccountHolders($transaction, 'reversed');

        } catch (\Exception $e) {
            Log::error('SMSPNotificationObserver: Failed to send SMS reversal notification', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("SMS notification failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onScheduledTransactionExecuted(Transaction $transaction): void
    {
        // No SMS for scheduled transactions by default
    }

    private function shouldSendSMSTransaction(Transaction $transaction): bool
    {
        // Always send for failed transactions
        if ($transaction->status === 'failed') {
            return true;
        }

        // Send for large transactions
        if ($transaction->amount >= $this->smsSettings['large_transaction_threshold']) {
            return true;
        }

        // Send for withdrawals over a certain amount
        if ($transaction->type === 'withdrawal' && $transaction->amount >= 1000) {
            return true;
        }

        // Send for international transfers
        if ($transaction->metadata['is_international'] ?? false) {
            return true;
        }

        return false;
    }

    private function notifyAccountHolders(Transaction $transaction, string $type): void
    {
        $accounts = $this->getAccountsToNotify($transaction);

        foreach ($accounts as $account) {
            $users = $account->users()
                ->where('sms_notifications', true)
                ->where('phone', '!=', null)
                ->get();

            foreach ($users as $user) {
                if ($this->canSendSMS($user)) {
                    $this->sendNotificationToUser($user, $transaction, $type);
                    $this->logSMSSent($user, $transaction);
                }
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

    private function canSendSMS(User $user): bool
    {
        // Check daily SMS limit
        $todayStart = now()->startOfDay();
        $smsCount = $user->smsLogs()
            ->where('created_at', '>=', $todayStart)
            ->count();

        if ($smsCount >= $this->smsSettings['daily_limit']) {
            Log::warning('SMSPNotificationObserver: Daily SMS limit reached', [
                'user_id' => $user->id,
                'daily_limit' => $this->smsSettings['daily_limit'],
                'current_count' => $smsCount
            ]);
            return false;
        }

        // Check cooldown period
        $lastSMS = $user->smsLogs()
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastSMS && $lastSMS->created_at->diffInMinutes(now()) < $this->smsSettings['cooldown_minutes']) {
            Log::debug('SMSPNotificationObserver: SMS cooldown period active', [
                'user_id' => $user->id,
                'last_sms_time' => $lastSMS->created_at->format('Y-m-d H:i:s'),
                'cooldown_minutes' => $this->smsSettings['cooldown_minutes']
            ]);
            return false;
        }

        return true;
    }

    private function sendNotificationToUser(User $user, Transaction $transaction, string $type): void
    {
        $notification = match($type) {
            'completed' => new SMSTransactionAlert($transaction),
            'failed' => new SMSTransactionFailed($transaction),
            'approved' => new SMSTransactionApproved($transaction),
            'reversed' => new SMSTransactionReversed($transaction),
            default => new SMSTransactionAlert($transaction)
        };

        $user->notify($notification);
    }

    private function logSMSSent(User $user, Transaction $transaction): void
    {
        $user->smsLogs()->create([
            'transaction_id' => $transaction->id,
            'message_type' => $transaction->type,
            'amount' => $transaction->amount,
            'status' => 'sent'
        ]);
    }

    public function getName(): string
    {
        return 'SMSPNotificationObserver';
    }

    public function isEnabled(): bool
    {
        return $this->enabled && config('services.sms.enabled', true);
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
