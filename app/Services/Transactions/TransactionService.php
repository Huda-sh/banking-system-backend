<?php

namespace App\Services\Transactions;

use App\Enums\Direction;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Account;
use App\Exceptions\TransactionException;
use App\Exceptions\ApprovalException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TransactionService
{
    /**
     * Process a transaction based on the provided data.
     */
    public function process(array $data, User $user): Transaction
    {
        DB::beginTransaction();

        try {
            // Validate transaction data
            $this->validateTransactionData($data, $user);

            // Create transaction record
            $transaction = $this->createTransaction($data, $user);

            // Process the transaction based on type
            $this->processTransactionType($transaction);

            // Check if approval is required
            if ($this->requiresApproval($transaction, $user)) {
                $transaction->update(['status' => TransactionStatus::PENDING_APPROVAL]);
                DB::commit();
                throw new ApprovalException($transaction);
            }

            // Execute the transaction
            $this->executeTransaction($transaction);

            DB::commit();

            return $transaction;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Transaction processing failed', [
                'user_id' => $user->id,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get transactions for a user with filters.
     */
    public function getTransactionsForUser(User $user, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Transaction::query();

        // Apply user-specific filters
        if (!$user->hasRole('admin')) {
            $query->where('initiated_by', $user->id);
        }

        // Apply filters
        if (isset($filters['account_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('source_account_id', $filters['account_id'])
                    ->orWhere('target_account_id', $filters['account_id']);
            });
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        if (isset($filters['min_amount'])) {
            $query->where('amount', '>=', $filters['min_amount']);
        }

        if (isset($filters['max_amount'])) {
            $query->where('amount', '<=', $filters['max_amount']);
        }

        return $query->with(['sourceAccount', 'targetAccount', 'initiatedBy'])
            ->latest()
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get account history for a user.
     */
    public function getAccountHistory(User $user, string $accountId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // Verify user has access to the account
        $account = Account::findOrFail($accountId);
        if (!$user->hasRole('admin') && !$user->accounts()->where('id', $accountId)->exists()) {
            throw new TransactionException('Unauthorized access to account');
        }

        $query = Transaction::where(function ($q) use ($accountId) {
            $q->where('source_account_id', $accountId)
                ->orWhere('target_account_id', $accountId);
        });

        // Apply filters
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        return $query->with(['sourceAccount', 'targetAccount', 'initiatedBy'])
            ->latest()
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get user transaction summary.
     */
    public function getUserTransactionSummary(User $user, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = Transaction::where('initiated_by', $user->id)
            ->where('status', TransactionStatus::COMPLETED);

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $transactions = $query->get();

        return [
            'total_transactions' => $transactions->count(),
            'total_amount' => $transactions->sum('amount'),
            'total_fees' => $transactions->sum('fee'),
            'by_type' => $transactions->groupBy('type')->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'amount' => $group->sum('amount'),
                    'fees' => $group->sum('fee')
                ];
            }),
            'by_status' => $transactions->groupBy('status')->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'amount' => $group->sum('amount')
                ];
            })
        ];
    }

    /**
     * Reverse a transaction.
     */
    public function reverse(Transaction $transaction, User $user, string $reason): Transaction
    {
        if (!$this->canReverseTransaction($transaction, $user)) {
            throw new TransactionException('Transaction cannot be reversed');
        }

        DB::beginTransaction();

        try {
            // Create reversal transaction
            $reversalData = [
                'reference_number' => 'REV-' . $transaction->reference_number,
                'description' => 'Reversal: ' . $transaction->description,
                'source_account_id' => $transaction->target_account_id,
                'target_account_id' => $transaction->source_account_id,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'type' => $transaction->type,
                'direction' => $transaction->direction === Direction::DEBIT ? Direction::CREDIT : Direction::DEBIT,
                'initiated_by' => $user->id,
                'processed_by' => $user->id,
                'status' => TransactionStatus::COMPLETED
            ];

            $reversalTransaction = Transaction::create($reversalData);

            // Update original transaction status
            $transaction->update(['status' => TransactionStatus::APPROVAL_NOT_REQUIRED]);

            // Execute the reversal
            $this->executeTransaction($reversalTransaction);

            DB::commit();

            return $reversalTransaction;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Transaction reversal failed', [
                'transaction_id' => $transaction->id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Cancel a transaction.
     */
    public function cancel(Transaction $transaction, User $user, string $reason): bool
    {
        if (!$this->canCancelTransaction($transaction, $user)) {
            throw new TransactionException('Transaction cannot be cancelled');
        }

        $transaction->update([
            'status' => TransactionStatus::CANCELLED,
            'processed_by' => $user->id
        ]);

        return true;
    }

    /**
     * Validate transaction data.
     */
    private function validateTransactionData(array $data, User $user): void
    {
        // Basic validation
        if (!isset($data['type']) || !in_array($data['type'], ['deposit', 'withdrawal', 'transfer'])) {
            throw new TransactionException('Invalid transaction type');
        }

        if (!isset($data['amount']) || $data['amount'] <= 0) {
            throw new TransactionException('Invalid amount');
        }

        // Type-specific validation
        switch ($data['type']) {
            case 'deposit':
                if (!isset($data['target_account_id'])) {
                    throw new TransactionException('Target account is required for deposits');
                }
                break;

            case 'withdrawal':
                if (!isset($data['source_account_id'])) {
                    throw new TransactionException('Source account is required for withdrawals');
                }
                break;

            case 'transfer':
                if (!isset($data['source_account_id']) || !isset($data['target_account_id'])) {
                    throw new TransactionException('Both source and target accounts are required for transfers');
                }
                if ($data['source_account_id'] === $data['target_account_id']) {
                    throw new TransactionException('Source and target accounts cannot be the same');
                }
                break;
        }

        // Account ownership validation
        if (isset($data['source_account_id'])) {
            $sourceAccount = Account::find($data['source_account_id']);
            if (!$sourceAccount || (!$user->hasRole('admin') && !$user->accounts()->where('id', $data['source_account_id'])->exists())) {
                throw new TransactionException('Invalid source account');
            }
        }

        if (isset($data['target_account_id'])) {
            $targetAccount = Account::find($data['target_account_id']);
            if (!$targetAccount || (!$user->hasRole('admin') && !$user->accounts()->where('id', $data['target_account_id'])->exists())) {
                throw new TransactionException('Invalid target account');
            }
        }
    }

    /**
     * Create transaction record.
     */
    private function createTransaction(array $data, User $user): Transaction
    {
        $transactionData = [
            'reference_number' => $data['reference_number'] ?? $this->generateReferenceNumber(),
            'description' => $data['description'] ?? '',
            'source_account_id' => $data['source_account_id'] ?? null,
            'target_account_id' => $data['target_account_id'] ?? null,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'USD',
            'type' => $data['type'],
            'direction' => $this->determineDirection($data),
            'initiated_by' => $user->id,
            'status' => TransactionStatus::PENDING
        ];

        return Transaction::create($transactionData);
    }

    /**
     * Process transaction based on type.
     */
    private function processTransactionType(Transaction $transaction): void
    {
        // Additional processing based on transaction type
        // This could include creating related records in deposits, withdrawals, transfers tables
    }

    /**
     * Check if transaction requires approval.
     */
    private function requiresApproval(Transaction $transaction, User $user): bool
    {
        // Approval logic based on amount, user role, etc.
        // For now, require approval for transactions over $1000
        return $transaction->amount > 1000;
    }

    /**
     * Execute the transaction.
     */
    private function executeTransaction(Transaction $transaction): void
    {
        // Update account balances
        switch ($transaction->type) {
            case TransactionType::DEPOSIT:
                $targetAccount = Account::find($transaction->target_account_id);
                $targetAccount->increment('balance', $transaction->amount);
                break;

            case TransactionType::WITHDRAWAL:
                $sourceAccount = Account::find($transaction->source_account_id);
                $sourceAccount->decrement('balance', $transaction->amount);
                break;

            case TransactionType::TRANSFER:
                $sourceAccount = Account::find($transaction->source_account_id);
                $targetAccount = Account::find($transaction->target_account_id);
                $sourceAccount->decrement('balance', $transaction->amount);
                $targetAccount->increment('balance', $transaction->amount);
                break;
        }

        $transaction->update([
            'status' => TransactionStatus::COMPLETED,
            'processed_by' => $transaction->initiated_by
        ]);
    }

    /**
     * Determine transaction direction.
     */
    private function determineDirection(array $data): Direction
    {
        return match ($data['type']) {
            'deposit' => Direction::CREDIT,
            'withdrawal' => Direction::DEBIT,
            'transfer' => Direction::DEBIT, // For the source account
            default => Direction::DEBIT
        };
    }

    /**
     * Check if transaction can be reversed.
     */
    private function canReverseTransaction(Transaction $transaction, User $user): bool
    {
        return $transaction->status === TransactionStatus::COMPLETED &&
               $transaction->created_at->diffInDays(now()) <= 30; // Within 30 days
    }

    /**
     * Check if transaction can be cancelled.
     */
    private function canCancelTransaction(Transaction $transaction, User $user): bool
    {
        return in_array($transaction->status, [TransactionStatus::PENDING, TransactionStatus::PENDING_APPROVAL]);
    }

    /**
     * Generate unique reference number.
     */
    private function generateReferenceNumber(): string
    {
        do {
            $reference = 'TXN-' . strtoupper(substr(md5(uniqid()), 0, 8));
        } while (Transaction::where('reference_number', $reference)->exists());

        return $reference;
    }
}
