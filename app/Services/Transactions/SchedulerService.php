<?php

namespace App\Services;

use App\Models\ScheduledTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use App\Exceptions\ScheduledTransactionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SchedulerService
{
    /**
     * Maximum number of transactions to process in a single batch.
     */
    const BATCH_SIZE = 100;

    /**
     * SchedulerService constructor.
     */
    public function __construct(
        private TransactionService $transactionService
    ) {}

    /**
     * Process all due scheduled transactions.
     */
    public function processDueTransactions(): array
    {
        $results = [
            'processed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'transactions' => []
        ];

        $dueTransactions = $this->getDueTransactions();

        foreach ($dueTransactions as $scheduled) {
            try {
                $result = $this->processSingleScheduledTransaction($scheduled);

                $results['processed']++;
                $results['transactions'][] = [
                    'id' => $scheduled->id,
                    'transaction_id' => $result->id,
                    'status' => 'success',
                    'amount' => $result->amount,
                    'next_execution' => $scheduled->next_execution?->format('Y-m-d H:i:s')
                ];

            } catch (\Exception $e) {
                $results['failed']++;
                $results['transactions'][] = [
                    'id' => $scheduled->id,
                    'error' => $e->getMessage(),
                    'status' => 'failed'
                ];

                Log::error('Failed to process scheduled transaction', [
                    'scheduled_id' => $scheduled->id,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e)
                ]);

                // Handle failure - deactivate or retry logic
                $this->handleProcessingFailure($scheduled, $e);
            }
        }

        return $results;
    }

    /**
     * Get all due scheduled transactions.
     */
    private function getDueTransactions(): Collection
    {
        return ScheduledTransaction::due()
            ->with('transaction')
            ->limit(self::BATCH_SIZE)
            ->get();
    }

    /**
     * Process a single scheduled transaction.
     */
    private function processSingleScheduledTransaction(ScheduledTransaction $scheduled): Transaction
    {
        return DB::transaction(function () use ($scheduled) {
            // Update next execution before processing to avoid duplicate processing
            $scheduled->execution_count++;
            $scheduled->next_execution = $scheduled->getNextExecutionDate();
            $scheduled->save();

            $transaction = $scheduled->transaction;

            // Check if transaction can be executed
            if (!$scheduled->canBeExecuted()) {
                throw new ScheduledTransactionException('Scheduled transaction cannot be executed');
            }

            // Prepare transaction data
            $transactionData = [
                'from_account_id' => $transaction->from_account_id,
                'to_account_id' => $transaction->to_account_id,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'type' => TransactionType::SCHEDULED,
                'description' => $this->getScheduledDescription($transaction),
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'scheduled_transaction_id' => $scheduled->id,
                    'original_transaction_id' => $transaction->id,
                    'execution_count' => $scheduled->execution_count,
                    'scheduled_frequency' => $scheduled->frequency
                ])
            ];

            // Process the transaction
            $result = $this->transactionService->process(
                $transactionData,
                $transaction->initiatedBy
            );

            // Check if we need to deactivate the schedule
            if ($scheduled->max_executions && $scheduled->execution_count >= $scheduled->max_executions) {
                $scheduled->is_active = false;
                $scheduled->save();
            }

            return $result;
        });
    }

    /**
     * Get description for scheduled transaction.
     */
    private function getScheduledDescription(Transaction $originalTransaction): string
    {
        $baseDescription = $originalTransaction->description ?? 'Scheduled transaction';

        if ($originalTransaction->scheduledTransaction) {
            $frequency = match($originalTransaction->scheduledTransaction->frequency) {
                'daily' => 'Daily',
                'weekly' => 'Weekly',
                'monthly' => 'Monthly',
                'yearly' => 'Yearly',
                default => 'Recurring'
            };

            return sprintf("%s - %s #%d", $baseDescription, $frequency, $originalTransaction->scheduledTransaction->execution_count + 1);
        }

        return $baseDescription;
    }

    /**
     * Handle processing failure for scheduled transaction.
     */
    private function handleProcessingFailure(ScheduledTransaction $scheduled, \Exception $exception): void
    {
        // Log the failure
        Log::error('Scheduled transaction processing failed', [
            'scheduled_id' => $scheduled->id,
            'transaction_id' => $scheduled->transaction_id,
            'error' => $exception->getMessage(),
            'exception' => get_class($exception)
        ]);

        // Update transaction status
        $transaction = $scheduled->transaction;
        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'last_failure' => $exception->getMessage(),
                'failure_time' => now()->format('Y-m-d H:i:s'),
                'failure_count' => ($transaction->metadata['failure_count'] ?? 0) + 1
            ])
        ]);

        // Handle based on failure count
        $failureCount = $transaction->metadata['failure_count'] ?? 1;

        if ($failureCount >= 3) {
            // Deactivate after 3 failures
            $scheduled->is_active = false;
            $scheduled->save();

            Log::warning('Scheduled transaction deactivated after multiple failures', [
                'scheduled_id' => $scheduled->id,
                'failure_count' => $failureCount
            ]);
        } else {
            // Retry after some time
            $retryDelay = match(true) {
                $failureCount === 1 => now()->addHours(1),
                $failureCount === 2 => now()->addHours(6),
                default => now()->addDay()
            };

            $scheduled->next_execution = $retryDelay;
            $scheduled->save();
        }
    }

    /**
     * Create a new scheduled transaction.
     */
    public function createScheduledTransaction(array $data, User $initiatedBy): ScheduledTransaction
    {
        return DB::transaction(function () use ($data, $initiatedBy) {
            // Validate schedule data
            $this->validateScheduleData($data);

            // Create the base transaction (template)
            $transactionData = array_merge($data, [
                'status' => TransactionStatus::SCHEDULED,
                'type' => TransactionType::SCHEDULED
            ]);

            $transaction = $this->transactionService->process($transactionData, $initiatedBy);

            // Create the scheduled transaction record
            return ScheduledTransaction::createFromTransaction($transaction, $data);
        });
    }

    /**
     * Validate schedule data before creation.
     */
    private function validateScheduleData(array $data): void
    {
        if (!isset($data['frequency']) || !array_key_exists($data['frequency'], ScheduledTransaction::FREQUENCIES)) {
            throw new ScheduledTransactionException('Invalid frequency specified');
        }

        if (!isset($data['start_date'])) {
            throw new ScheduledTransactionException('Start date is required');
        }

        if (isset($data['max_executions']) && $data['max_executions'] <= 0) {
            throw new ScheduledTransactionException('Maximum executions must be greater than 0');
        }

        if (Carbon::parse($data['start_date'])->isPast()) {
            throw new ScheduledTransactionException('Start date cannot be in the past');
        }
    }

    /**
     * Update an existing scheduled transaction.
     */
    public function updateScheduledTransaction(ScheduledTransaction $scheduled, array $data): ScheduledTransaction
    {
        return DB::transaction(function () use ($scheduled, $data) {
            // Don't allow updating if it's already completed or inactive
            if (!$scheduled->is_active || $scheduled->execution_count > 0) {
                throw new ScheduledTransactionException('Cannot update scheduled transaction that has already been executed');
            }

            // Validate updates
            $this->validateScheduleUpdates($scheduled, $data);

            // Update the base transaction if needed
            if (isset($data['amount']) || isset($data['description'])) {
                $transaction = $scheduled->transaction;

                if (isset($data['amount'])) {
                    $transaction->amount = $data['amount'];
                }

                if (isset($data['description'])) {
                    $transaction->description = $data['description'];
                }

                $transaction->save();
            }

            // Update schedule details
            if (isset($data['frequency'])) {
                $scheduled->frequency = $data['frequency'];
            }

            if (isset($data['next_execution'])) {
                $scheduled->next_execution = Carbon::parse($data['next_execution']);
            }

            if (isset($data['max_executions'])) {
                $scheduled->max_executions = $data['max_executions'];
            }

            if (isset($data['is_active'])) {
                $scheduled->is_active = $data['is_active'];
            }

            $scheduled->save();

            return $scheduled;
        });
    }

    /**
     * Validate schedule updates.
     */
    private function validateScheduleUpdates(ScheduledTransaction $scheduled, array $data): void
    {
        if (isset($data['next_execution']) && Carbon::parse($data['next_execution'])->isPast()) {
            throw new ScheduledTransactionException('Next execution date cannot be in the past');
        }

        if (isset($data['max_executions']) && $data['max_executions'] < $scheduled->execution_count) {
            throw new ScheduledTransactionException('Maximum executions cannot be less than execution count');
        }
    }

    /**
     * Cancel a scheduled transaction.
     */
    public function cancelScheduledTransaction(ScheduledTransaction $scheduled, User $cancelledBy, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($scheduled, $cancelledBy, $reason) {
            if (!$scheduled->is_active) {
                throw new ScheduledTransactionException('Scheduled transaction is already inactive');
            }

            // Update schedule
            $scheduled->is_active = false;
            $scheduled->save();

            // Update the base transaction
            $transaction = $scheduled->transaction;
            $transaction->update([
                'status' => TransactionStatus::CANCELLED,
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'cancelled_by' => $cancelledBy->id,
                    'cancelled_at' => now()->format('Y-m-d H:i:s'),
                    'cancellation_reason' => $reason,
                    'cancelled_schedule_id' => $scheduled->id
                ])
            ]);

            Log::info('Scheduled transaction cancelled', [
                'scheduled_id' => $scheduled->id,
                'transaction_id' => $transaction->id,
                'cancelled_by' => $cancelledBy->id,
                'reason' => $reason
            ]);

            return true;
        });
    }

    /**
     * Get upcoming scheduled transactions for a user.
     */
    public function getUserUpcomingSchedules(User $user, ?Carbon $startDate = null, ?Carbon $endDate = null, int $limit = 20): Collection
    {
        $query = ScheduledTransaction::active()
            ->whereHas('transaction', function ($q) use ($user) {
                $q->where('initiated_by', $user->id);
            })
            ->with(['transaction', 'transaction.fromAccount', 'transaction.toAccount'])
            ->orderBy('next_execution', 'asc');

        if ($startDate && $endDate) {
            $query->whereBetween('next_execution', [$startDate, $endDate]);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Get schedule execution history.
     */
    public function getScheduleHistory(ScheduledTransaction $scheduled, int $limit = 50): Collection
    {
        return Transaction::where('metadata->scheduled_transaction_id', $scheduled->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Reactivate a scheduled transaction.
     */
    public function reactivateSchedule(ScheduledTransaction $scheduled, ?Carbon $nextExecution = null): ScheduledTransaction
    {
        if ($scheduled->is_active) {
            throw new ScheduledTransactionException('Schedule is already active');
        }

        $scheduled->is_active = true;
        $scheduled->next_execution = $nextExecution ?? now()->addDay();
        $scheduled->save();

        return $scheduled;
    }
}
