<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Events\TransactionApproved;
use App\Events\TransactionFailed;
use App\Events\ScheduledTransactionExecuted;
use App\Notifications\TransactionCreatedNotification;
use App\Notifications\TransactionApprovedNotification;
use App\Notifications\TransactionFailedNotification;
use App\Notifications\ScheduledTransactionExecutedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;

class SendTransactionNotification
{
    /**
     * Handle the transaction created event.
     */
    public function handleTransactionCreated(TransactionCreated $event): void
    {
        try {
            $this->sendNotifications($event->transaction, 'created');

            Log::info('SendTransactionNotification: Created notifications sent', [
                'transaction_id' => $event->transaction->id
            ]);

        } catch (\Exception $e) {
            Log::error('SendTransactionNotification: Failed to send created notifications', [
                'transaction_id' => $event->transaction->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle the transaction approved event.
     */
    public function handleTransactionApproved(TransactionApproved $event): void
    {
        try {
            $this->sendNotifications($event->transaction, 'approved');

            Log::info('SendTransactionNotification: Approved notifications sent', [
                'transaction_id' => $event->transaction->id
            ]);

        } catch (\Exception $e) {
            Log::error('SendTransactionNotification: Failed to send approved notifications', [
                'transaction_id' => $event->transaction->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle the transaction failed event.
     */
    public function handleTransactionFailed(TransactionFailed $event): void
    {
        try {
            $this->sendNotifications($event->transaction, 'failed');

            Log::info('SendTransactionNotification: Failed notifications sent', [
                'transaction_id' => $event->transaction->id
            ]);

        } catch (\Exception $e) {
            Log::error('SendTransactionNotification: Failed to send failed notifications', [
                'transaction_id' => $event->transaction->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle the scheduled transaction executed event.
     */
    public function handleScheduledExecuted(ScheduledTransactionExecuted $event): void
    {
        try {
            $this->sendNotifications($event->transaction, 'scheduled_executed', $event->scheduled);

            Log::info('SendTransactionNotification: Scheduled execution notifications sent', [
                'transaction_id' => $event->transaction->id,
                'scheduled_id' => $event->scheduled->id
            ]);

        } catch (\Exception $e) {
            Log::error('SendTransactionNotification: Failed to send scheduled execution notifications', [
                'transaction_id' => $event->transaction->id,
                'scheduled_id' => $event->scheduled->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send notifications to relevant users.
     */
    private function sendNotifications($transaction, string $type, $scheduled = null): void
    {
        $users = $this->getUsersToNotify($transaction);

        foreach ($users as $user) {
            $notification = $this->createNotification($transaction, $type, $scheduled, $user);
            $user->notify($notification);
        }
    }

    /**
     * Get users to notify for a transaction.
     */
    private function getUsersToNotify($transaction): array
    {
        $users = [];

        // Add account users
        if ($transaction->fromAccount) {
            $users = array_merge($users, $transaction->fromAccount->users->toArray());
        }

        if ($transaction->toAccount) {
            $users = array_merge($users, $transaction->toAccount->users->toArray());
        }

        // Add approvers for pending/failed transactions
        if (in_array($transaction->status, ['pending_approval', 'failed'])) {
            $approvers = $this->getApprovers($transaction);
            $users = array_merge($users, $approvers);
        }

        // Add admins for large transactions
        if ($transaction->amount >= 10000) {
            $admins = $this->getAdminUsers();
            $users = array_merge($users, $admins);
        }

        // Remove duplicates
        return array_unique($users, SORT_REGULAR);
    }

    /**
     * Create appropriate notification based on type.
     */
    private function createNotification($transaction, string $type, $scheduled = null, $user = null)
    {
        return match($type) {
            'created' => new TransactionCreatedNotification($transaction, $user),
            'approved' => new TransactionApprovedNotification($transaction, $user),
            'failed' => new TransactionFailedNotification($transaction, $user),
            'scheduled_executed' => new ScheduledTransactionExecutedNotification($transaction, $scheduled, $user),
            default => new TransactionCreatedNotification($transaction, $user)
        };
    }

    /**
     * Get approvers for a transaction.
     */
    private function getApprovers($transaction): array
    {
        // In production, this would get actual approvers
        return [];
    }

    /**
     * Get admin users.
     */
    private function getAdminUsers(): array
    {
        // In production, this would get actual admin users
        return [];
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe($events): void
    {
        $events->listen(
            TransactionCreated::class,
            [SendTransactionNotification::class, 'handleTransactionCreated']
        );

        $events->listen(
            TransactionApproved::class,
            [SendTransactionNotification::class, 'handleTransactionApproved']
        );

        $events->listen(
            TransactionFailed::class,
            [SendTransactionNotification::class, 'handleTransactionFailed']
        );

        $events->listen(
            ScheduledTransactionExecuted::class,
            [SendTransactionNotification::class, 'handleScheduledExecuted']
        );
    }
}
