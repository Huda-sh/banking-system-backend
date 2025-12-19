<?php

namespace App\Patterns\Observer\Observers;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Account;
use App\Models\TransactionAuditLog;
use App\Enums\AuditAction;
use App\Patterns\Observer\Interfaces\TransactionObserver;
use Illuminate\Support\Facades\Log;
use App\Exceptions\ObserverException;
use Carbon\Carbon;

class AuditLogObserver implements TransactionObserver
{
    private int $priority = 10; // High priority - should run first
    private bool $enabled = true;
    private array $sensitiveFields = ['balance', 'amount', 'fee', 'account_number', 'user_id'];

    public function __construct()
    {
        $this->enabled = config('banking.audit_logging.enabled', true);
    }

    public function onTransactionCreated(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            $this->createAuditLog($transaction, AuditAction::CREATED, [
                'initial_status' => $transaction->status->value,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'type' => $transaction->type->value
            ]);

        } catch (\Exception $e) {
            Log::error('AuditLogObserver: Failed to create audit log for transaction creation', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Audit logging failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onTransactionCompleted(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            $this->createAuditLog($transaction, AuditAction::EXECUTED, [
                'final_status' => 'completed',
                'processed_by' => $transaction->processed_by,
                'approved_at' => $transaction->approved_at?->format('Y-m-d H:i:s'),
                'execution_time' => now()->format('Y-m-d H:i:s')
            ]);

            $this->logAccountBalanceChanges($transaction);

        } catch (\Exception $e) {
            Log::error('AuditLogObserver: Failed to create audit log for transaction completion', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Audit logging failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onTransactionFailed(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            $this->createAuditLog($transaction, AuditAction::FAILED, [
                'final_status' => 'failed',
                'error' => $transaction->metadata['error'] ?? 'Unknown error',
                'error_class' => $transaction->metadata['error_class'] ?? null
            ]);

        } catch (\Exception $e) {
            Log::error('AuditLogObserver: Failed to create audit log for transaction failure', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Audit logging failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onTransactionApproved(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            $this->createAuditLog($transaction, AuditAction::APPROVED, [
                'approved_by' => $transaction->approved_by,
                'approved_at' => $transaction->approved_at?->format('Y-m-d H:i:s'),
                'approval_level' => $transaction->metadata['approval_level'] ?? 'standard'
            ]);

        } catch (\Exception $e) {
            Log::error('AuditLogObserver: Failed to create audit log for transaction approval', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Audit logging failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onTransactionReversed(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            $this->createAuditLog($transaction, AuditAction::REVERSED, [
                'reversed_by' => $transaction->metadata['reversed_by'] ?? 'system',
                'reversed_at' => $transaction->metadata['reversed_at'] ?? now()->format('Y-m-d H:i:s'),
                'reversal_reason' => $transaction->metadata['reversal_reason'] ?? 'Not specified',
                'original_transaction_id' => $transaction->metadata['original_transaction_id'] ?? null
            ]);

            $this->logAccountBalanceChanges($transaction, true); // true for reversal

        } catch (\Exception $e) {
            Log::error('AuditLogObserver: Failed to create audit log for transaction reversal', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Audit logging failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onScheduledTransactionExecuted(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            $this->createAuditLog($transaction, AuditAction::EXECUTED, [
                'scheduled_transaction_id' => $transaction->scheduledTransaction->id ?? null,
                'execution_count' => $transaction->scheduledTransaction->execution_count ?? 1,
                'scheduled_frequency' => $transaction->scheduledTransaction->frequency ?? 'once'
            ]);

        } catch (\Exception $e) {
            Log::error('AuditLogObserver: Failed to create audit log for scheduled execution', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Audit logging failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function createAuditLog(Transaction $transaction, AuditAction $action, array $additionalData = []): void
    {
        $currentUser = auth()->user() ?? User::find($transaction->initiated_by);

        $logData = [
            'transaction_id' => $transaction->id,
            'user_id' => $currentUser?->id,
            'action' => $action,
            'ip_address' => request()->ip() ?? 'system',
            'old_data' => $this->getOldData($transaction, $action),
            'new_data' => $this->getNewData($transaction, $action, $additionalData),
            'additional_info' => $additionalData
        ];

        // Mask sensitive data
        $logData['old_data'] = $this->maskSensitiveData($logData['old_data']);
        $logData['new_data'] = $this->maskSensitiveData($logData['new_data']);

        TransactionAuditLog::create($logData);
    }

    private function getOldData(Transaction $transaction, AuditAction $action): ?array
    {
        if (in_array($action, [AuditAction::CREATED, AuditAction::EXECUTED])) {
            return null;
        }

        return $transaction->replicate()->toArray();
    }

    private function getNewData(Transaction $transaction, AuditAction $action, array $additionalData = []): array
    {
        $data = $transaction->toArray();

        // Add action-specific data
        $data['action'] = $action->value;
        $data['action_time'] = now()->format('Y-m-d H:i:s');

        // Merge additional data
        return array_merge($data, $additionalData);
    }

    private function maskSensitiveData(?array $data): ?array
    {
        if (!$data) return null;

        $masked = $data;

        foreach ($masked as $key => $value) {
            if (in_array($key, $this->sensitiveFields) && is_string($value)) {
                // Mask account numbers, user IDs, etc.
                if (str_contains($key, 'account_number') || str_contains($key, 'user_id')) {
                    $masked[$key] = str_repeat('*', max(4, strlen($value) - 4)) . substr($value, -4);
                }
            }
        }

        return $masked;
    }

    private function logAccountBalanceChanges(Transaction $transaction, bool $isReversal = false): void
    {
        if ($transaction->from_account_id) {
            $this->logAccountBalanceChange($transaction->from_account_id, $transaction, $isReversal, 'from');
        }

        if ($transaction->to_account_id) {
            $this->logAccountBalanceChange($transaction->to_account_id, $transaction, $isReversal, 'to');
        }
    }

    private function logAccountBalanceChange(int $accountId, Transaction $transaction, bool $isReversal, string $direction): void
    {
        $account = Account::withTrashed()->find($accountId);

        if (!$account) return;

        $amount = $direction === 'from'
            ? ($isReversal ? $transaction->amount + $transaction->fee : -($transaction->amount + $transaction->fee))
            : ($isReversal ? -$transaction->amount : $transaction->amount);

        $newBalance = $account->balance + $amount;

        $this->createAuditLog($transaction, AuditAction::UPDATED, [
            'account_id' => $account->id,
            'account_number' => $account->account_number,
            'direction' => $direction,
            'amount_change' => $amount,
            'previous_balance' => $account->balance,
            'new_balance' => $newBalance,
            'is_reversal' => $isReversal,
            'currency' => $transaction->currency
        ]);
    }

    public function getName(): string
    {
        return 'AuditLogObserver';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
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
