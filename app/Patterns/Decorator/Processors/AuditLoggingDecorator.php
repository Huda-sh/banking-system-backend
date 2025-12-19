<?php

namespace App\Patterns\Decorator\Processors;

use App\Models\Transaction;
use App\Models\TransactionAuditLog;
use App\Models\User;
use App\Enums\AuditAction;
use App\Exceptions\ProcessorException;
use App\Patterns\Decorator\Interfaces\TransactionProcessor;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AuditLoggingDecorator implements TransactionProcessor
{
    private TransactionProcessor $processor;
    private bool $enabled = true;
    private array $sensitiveFields = ['balance', 'amount', 'fee', 'account_number', 'user_id'];

    public function __construct(TransactionProcessor $processor)
    {
        $this->processor = $processor;
        $this->enabled = config('banking.processors.audit_logging.enabled', true);
    }

    public function process(Transaction $transaction): bool
    {
        if (!$this->isEnabled()) {
            return $this->processor->process($transaction);
        }

        try {
            Log::debug('AuditLoggingDecorator: Starting audit logging for transaction', [
                'transaction_id' => $transaction->id,
                'type' => $transaction->type->value
            ]);

            // Create audit log before processing
            $preLog = $this->createPreProcessingLog($transaction);

            // Process the transaction
            $result = $this->processor->process($transaction);

            // Create audit log after processing
            $this->createPostProcessingLog($transaction, $preLog, $result);

            Log::info('AuditLoggingDecorator: Audit logging completed successfully', [
                'transaction_id' => $transaction->id,
                'result' => $result
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('AuditLoggingDecorator: Error during audit logging', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);

            // Don't rethrow - audit logging should not block transaction processing
            return $this->processor->process($transaction);
        }
    }

    private function createPreProcessingLog(Transaction $transaction): ?TransactionAuditLog
    {
        try {
            $currentUser = auth()->user() ?? User::find($transaction->initiated_by);

            return TransactionAuditLog::create([
                'transaction_id' => $transaction->id,
                'user_id' => $currentUser?->id,
                'action' => AuditAction::PROCESSING_STARTED,
                'ip_address' => request()->ip() ?? 'system',
                'old_data' => $this->maskSensitiveData($transaction->toArray()),
                'new_data' => null,
                'additional_info' => [
                    'processor' => $this->getName(),
                    'started_at' => now()->format('Y-m-d H:i:s'),
                    'status_before' => $transaction->status->value
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('AuditLoggingDecorator: Failed to create pre-processing log', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function createPostProcessingLog(Transaction $transaction, ?TransactionAuditLog $preLog, bool $result): void
    {
        try {
            $currentUser = auth()->user() ?? User::find($transaction->initiated_by);
            $action = $result ? AuditAction::PROCESSING_COMPLETED : AuditAction::PROCESSING_FAILED;

            $logData = [
                'transaction_id' => $transaction->id,
                'user_id' => $currentUser?->id,
                'action' => $action,
                'ip_address' => request()->ip() ?? 'system',
                'old_data' => $preLog?->old_data,
                'new_data' => $this->maskSensitiveData($transaction->toArray()),
                'additional_info' => [
                    'processor' => $this->getName(),
                    'completed_at' => now()->format('Y-m-d H:i:s'),
                    'status_after' => $transaction->status->value,
                    'processing_result' => $result,
                    'processing_time' => $preLog ? now()->diffInSeconds($preLog->created_at) : null
                ]
            ];

            TransactionAuditLog::create($logData);

        } catch (\Exception $e) {
            Log::error('AuditLoggingDecorator: Failed to create post-processing log', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function maskSensitiveData(array $data): array
    {
        $masked = $data;

        foreach ($masked as $key => $value) {
            if (in_array($key, $this->sensitiveFields) && is_string($value)) {
                $masked[$key] = str_repeat('*', max(4, strlen($value) - 4)) . substr($value, -4);
            } elseif (is_array($value)) {
                $masked[$key] = $this->maskSensitiveData($value);
            }
        }

        return $masked;
    }

    public function validate(Transaction $transaction): bool
    {
        // Audit logging doesn't perform validation
        return $this->processor->validate($transaction);
    }

    public function getName(): string
    {
        return 'AuditLoggingDecorator';
    }

    public function getMetadata(): array
    {
        return [
            'name' => $this->getName(),
            'enabled' => $this->isEnabled(),
            'sensitive_fields' => $this->sensitiveFields,
            'audit_actions' => [
                'PROCESSING_STARTED' => AuditAction::PROCESSING_STARTED->value,
                'PROCESSING_COMPLETED' => AuditAction::PROCESSING_COMPLETED->value,
                'PROCESSING_FAILED' => AuditAction::PROCESSING_FAILED->value
            ]
        ];
    }

    public function setContext(array $context): self
    {
        $this->processor->setContext($context);
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && config('banking.audit_logging.enabled', true);
    }
}
