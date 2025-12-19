<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Events\TransactionApproved;
use App\Events\TransactionFailed;
use App\Events\ScheduledTransactionExecuted;
use App\Models\TransactionAuditLog;
use App\Enums\AuditAction;
use Illuminate\Support\Facades\Log;

class LogTransactionAudit
{
    /**
     * Handle transaction created event.
     */
    public function handleTransactionCreated(TransactionCreated $event): void
    {
        $this->createAuditLog($event->transaction, AuditAction::CREATED, [
            'initial_status' => $event->transaction->status->value,
            'amount' => $event->transaction->amount,
            'currency' => $event->transaction->currency,
            'type' => $event->transaction->type->value
        ]);
    }

    /**
     * Handle transaction approved event.
     */
    public function handleTransactionApproved(TransactionApproved $event): void
    {
        $this->createAuditLog($event->transaction, AuditAction::APPROVED, [
            'approved_by' => $event->transaction->approved_by,
            'approved_at' => $event->transaction->approved_at?->format('Y-m-d H:i:s'),
            'approval_level' => $event->transaction->metadata['approval_level'] ?? 'standard'
        ]);
    }

    /**
     * Handle transaction failed event.
     */
    public function handleTransactionFailed(TransactionFailed $event): void
    {
        $this->createAuditLog($event->transaction, AuditAction::FAILED, [
            'error' => $event->transaction->metadata['error'] ?? 'Unknown error',
            'error_class' => $event->transaction->metadata['error_class'] ?? null,
            'retry_count' => $event->transaction->metadata['retry_count'] ?? 0
        ]);
    }

    /**
     * Handle scheduled transaction executed event.
     */
    public function handleScheduledExecuted(ScheduledTransactionExecuted $event): void
    {
        $this->createAuditLog($event->transaction, AuditAction::EXECUTED, [
            'scheduled_transaction_id' => $event->scheduled->id,
            'execution_count' => $event->scheduled->execution_count,
            'frequency' => $event->scheduled->frequency,
            'next_execution' => $event->scheduled->next_execution?->format('Y-m-d H:i:s')
        ]);
    }

    /**
     * Create audit log entry.
     */
    private function createAuditLog($transaction, AuditAction $action, array $additionalData = []): void
    {
        try {
            TransactionAuditLog::create([
                'transaction_id' => $transaction->id,
                'user_id' => $transaction->initiated_by,
                'action' => $action,
                'ip_address' => request()->ip() ?? 'system',
                'old_data' => null,
                'new_data' => array_merge($transaction->toArray(), $additionalData),
                'additional_info' => [
                    'event_time' => now()->format('Y-m-d H:i:s'),
                    'event_source' => 'event_listener'
                ]
            ]);

            Log::info('LogTransactionAudit: Audit log created', [
                'transaction_id' => $transaction->id,
                'action' => $action->value
            ]);

        } catch (\Exception $e) {
            Log::error('LogTransactionAudit: Failed to create audit log', [
                'transaction_id' => $transaction->id,
                'action' => $action->value,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe($events): void
    {
        $events->listen(
            TransactionCreated::class,
            [LogTransactionAudit::class, 'handleTransactionCreated']
        );

        $events->listen(
            TransactionApproved::class,
            [LogTransactionAudit::class, 'handleTransactionApproved']
        );

        $events->listen(
            TransactionFailed::class,
            [LogTransactionAudit::class, 'handleTransactionFailed']
        );

        $events->listen(
            ScheduledTransactionExecuted::class,
            [LogTransactionAudit::class, 'handleScheduledExecuted']
        );
    }
}
