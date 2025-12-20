<?php

namespace App\Services\Transactions;

use App\Models\Transaction;
use App\Models\Account;
use App\Models\User;
use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use App\Enums\ApprovalLevel;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\AccountStateException;
use App\Exceptions\DailyLimitExceededException;
use App\Exceptions\ApprovalRequiredException;
use App\Exceptions\TransactionException;
use App\Patterns\ChainOfResponsibility\Interfaces\TransactionHandler;
use App\Patterns\ChainOfResponsibility\HandlerChainFactory;
use App\Patterns\Observer\TransactionSubject;
use App\Services\Transactions\ApprovalWorkflowService;
use App\Services\Transactions\FeeCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TransactionService
{
    /**
     * TransactionService constructor.
     */
    public function __construct(
        private HandlerChainFactory $handlerChainFactory,
        private TransactionSubject $transactionSubject,
        private ApprovalWorkflowService $approvalWorkflowService,
        private FeeCalculationService $feeCalculationService
    ) {}

    /**
     * Process a transaction with validation and approval workflow.
     *
     * @throws \Exception
     */
    public function process(array $data, User $initiatedBy): Transaction
    {
        return DB::transaction(function () use ($data, $initiatedBy) {
            // Create transaction record
            $transaction = $this->createTransactionRecord($data, $initiatedBy);

            try {
                // Validate transaction using Chain of Responsibility
                $requiresApproval = $this->validateTransaction($transaction);

                if ($requiresApproval) {
                    $transaction->status = TransactionStatus::PENDING_APPROVAL;
                    $transaction->save();

                    // Start approval workflow
                    $this->approvalWorkflowService->startWorkflow($transaction);

                    throw new ApprovalRequiredException(
                        'Transaction requires approval. Current status: ' . $transaction->status->getLabel(),
                        $transaction
                    );
                }

                // Execute the transaction
                $this->executeTransaction($transaction);

                // Update transaction status
                $transaction->update([
                    'status' => TransactionStatus::COMPLETED,
                    'processed_by' => $initiatedBy->id,
                    'approved_at' => now()
                ]);

                // Notify observers
                $this->notifyObservers($transaction);

                return $transaction;

            } catch (\Exception $e) {
                $this->handleTransactionFailure($transaction, $e);
                throw $e;
            }
        });
    }

    /**
     * Create transaction record.
     */
    private function createTransactionRecord(array $data, User $initiatedBy): Transaction
    {
        // Calculate fee using Strategy pattern
        $fee = $this->feeCalculationService->calculateFee(
            $data['type'],
            $data['amount'],
            $data['from_account_id'] ?? null,
            $data['to_account_id']
        );

        return Transaction::create([
            'from_account_id' => $data['from_account_id'] ?? null,
            'to_account_id' => $data['to_account_id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'USD',
            'type' => $data['type'],
            'status' => TransactionStatus::PENDING,
            'fee' => $fee,
            'initiated_by' => $initiatedBy->id,
            'description' => $data['description'] ?? $this->getDefaultDescription($data['type']),
            'ip_address' => request()->ip() ?? 'system',
            'metadata' => $data['metadata'] ?? []
        ]);
    }

    /**
     * Validate transaction using Chain of Responsibility pattern.
     *
     * @return bool True if approval is required, false otherwise
     */
    private function validateTransaction(Transaction $transaction): bool
    {
        $handlerChain = $this->handlerChainFactory->createChain();
        return !$handlerChain->handle($transaction);
    }

    /**
     * Execute the transaction (update account balances).
     */
    private function executeTransaction(Transaction $transaction): void
    {
        if ($transaction->isDeposit()) {
            $this->processDeposit($transaction);
        } elseif ($transaction->isWithdrawal()) {
            $this->processWithdrawal($transaction);
        } elseif ($transaction->isTransfer()) {
            $this->processTransfer($transaction);
        } else {
            throw new TransactionException('Unsupported transaction type: ' . $transaction->type->value);
        }
    }

    /**
     * Process a deposit transaction.
     */
    private function processDeposit(Transaction $transaction): void
    {
        $toAccount = Account::findOrFail($transaction->to_account_id);

        if (!$toAccount->currentState->canReceiveDeposits()) {
            throw new AccountStateException('Account cannot receive deposits in current state: ' . $toAccount->currentState->state);
        }

        $toAccount->increment('balance', $transaction->amount);
    }

    /**
     * Process a withdrawal transaction.
     */
    private function processWithdrawal(Transaction $transaction): void
    {
        $fromAccount = Account::findOrFail($transaction->from_account_id);

        if (!$fromAccount->currentState->canWithdraw()) {
            throw new AccountStateException('Account cannot withdraw in current state: ' . $fromAccount->currentState->state);
        }

        $availableBalance = $fromAccount->getAvailableBalance();

        if ($availableBalance < ($transaction->amount + $transaction->fee)) {
            throw new InsufficientBalanceException(
                sprintf('Insufficient balance. Available: %.2f, Required: %.2f',
                    $availableBalance,
                    $transaction->amount + $transaction->fee
                )
            );
        }

        $fromAccount->decrement('balance', $transaction->amount + $transaction->fee);
    }

    /**
     * Process a transfer transaction.
     */
    private function processTransfer(Transaction $transaction): void
    {
        $fromAccount = Account::findOrFail($transaction->from_account_id);
        $toAccount = Account::findOrFail($transaction->to_account_id);

        // Validate accounts
        if (!$fromAccount->currentState->canTransferFrom()) {
            throw new AccountStateException('Source account cannot transfer in current state: ' . $fromAccount->currentState->state);
        }

        if (!$toAccount->currentState->canTransferTo()) {
            throw new AccountStateException('Destination account cannot receive transfers in current state: ' . $toAccount->currentState->state);
        }

        // Check balance
        $availableBalance = $fromAccount->getAvailableBalance();

        if ($availableBalance < ($transaction->amount + $transaction->fee)) {
            throw new InsufficientBalanceException(
                sprintf('Insufficient balance. Available: %.2f, Required: %.2f',
                    $availableBalance,
                    $transaction->amount + $transaction->fee
                )
            );
        }

        // Execute transfer
        $fromAccount->decrement('balance', $transaction->amount + $transaction->fee);
        $toAccount->increment('balance', $transaction->amount);
    }

    /**
     * Handle transaction failure and rollback.
     */
    private function handleTransactionFailure(Transaction $transaction, \Exception $exception): void
    {
        Log::error('Transaction failed', [
            'transaction_id' => $transaction->id,
            'error' => $exception->getMessage(),
            'exception' => get_class($exception)
        ]);

        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'error' => $exception->getMessage(),
                'error_class' => get_class($exception),
                'error_time' => now()->format('Y-m-d H:i:s')
            ])
        ]);
    }

    /**
     * Notify observers about transaction completion.
     */
    private function notifyObservers(Transaction $transaction): void
    {
        $this->transactionSubject->attachObservers();
        $this->transactionSubject->setTransaction($transaction);
        $this->transactionSubject->notify();
    }

    /**
     * Get default description based on transaction type.
     */
    private function getDefaultDescription(TransactionType $type): string
    {
        return match($type) {
            TransactionType::DEPOSIT => 'Deposit transaction',
            TransactionType::WITHDRAWAL => 'Withdrawal transaction',
            TransactionType::TRANSFER => 'Transfer transaction',
            TransactionType::SCHEDULED => 'Scheduled transaction',
            TransactionType::LOAN_PAYMENT => 'Loan payment',
            TransactionType::INTEREST_PAYMENT => 'Interest payment',
            TransactionType::FEE_CHARGE => 'Fee charge',
            TransactionType::REVERSAL => 'Reversal transaction',
            TransactionType::ADJUSTMENT => 'Balance adjustment',
        };
    }

    /**
     * Reverse a completed transaction.
     */
    public function reverse(Transaction $transaction, User $initiatedBy, ?string $reason = null): Transaction
    {
        if (!$transaction->canBeReversed()) {
            throw new TransactionException('Transaction cannot be reversed. Status: ' . $transaction->status->value);
        }

        return DB::transaction(function () use ($transaction, $initiatedBy, $reason) {
            // Create reversal transaction
            $reversal = Transaction::create([
                'from_account_id' => $transaction->to_account_id,
                'to_account_id' => $transaction->from_account_id,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'type' => TransactionType::REVERSAL,
                'status' => TransactionStatus::COMPLETED,
                'fee' => 0.00, // No fee for reversals
                'initiated_by' => $initiatedBy->id,
                'processed_by' => $initiatedBy->id,
                'approved_by' => $initiatedBy->id,
                'approved_at' => now(),
                'description' => "Reversal of transaction #{$transaction->id}: " . ($reason ?? 'No reason provided'),
                'ip_address' => request()->ip() ?? 'system',
                'metadata' => [
                    'original_transaction_id' => $transaction->id,
                    'reversal_reason' => $reason,
                    'reversal_time' => now()->format('Y-m-d H:i:s')
                ]
            ]);

            // Execute reversal
            $this->executeReversal($transaction);

            // Update original transaction status
            $transaction->update([
                'status' => TransactionStatus::REVERSED,
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'reversed_by' => $initiatedBy->id,
                    'reversed_at' => now()->format('Y-m-d H:i:s'),
                    'reversal_transaction_id' => $reversal->id
                ])
            ]);

            // Notify observers about reversal
            $this->notifyObservers($reversal);

            return $reversal;
        });
    }

    /**
     * Execute the reversal of a transaction.
     */
    private function executeReversal(Transaction $originalTransaction): void
    {
        if ($originalTransaction->isDeposit()) {
            $account = Account::findOrFail($originalTransaction->to_account_id);
            $account->decrement('balance', $originalTransaction->amount);
        } elseif ($originalTransaction->isWithdrawal()) {
            $account = Account::findOrFail($originalTransaction->from_account_id);
            $account->increment('balance', $originalTransaction->amount + $originalTransaction->fee);
        } elseif ($originalTransaction->isTransfer()) {
            $fromAccount = Account::findOrFail($originalTransaction->from_account_id);
            $toAccount = Account::findOrFail($originalTransaction->to_account_id);

            $fromAccount->increment('balance', $originalTransaction->amount + $originalTransaction->fee);
            $toAccount->decrement('balance', $originalTransaction->amount);
        }
    }

    /**
     * Cancel a pending transaction.
     */
    public function cancel(Transaction $transaction, User $cancelledBy, ?string $reason = null): bool
    {
        if (!$transaction->canBeCancelled()) {
            throw new TransactionException('Transaction cannot be cancelled. Status: ' . $transaction->status->value);
        }

        return DB::transaction(function () use ($transaction, $cancelledBy, $reason) {
            $transaction->update([
                'status' => TransactionStatus::CANCELLED,
                'processed_by' => $cancelledBy->id,
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'cancelled_by' => $cancelledBy->id,
                    'cancelled_at' => now()->format('Y-m-d H:i:s'),
                    'cancellation_reason' => $reason
                ])
            ]);

            // Cancel any associated approvals
            if ($transaction->requiresApproval()) {
                $transaction->cancelPendingApprovals();
            }

            // Log the cancellation
            Log::info('Transaction cancelled', [
                'transaction_id' => $transaction->id,
                'cancelled_by' => $cancelledBy->id,
                'reason' => $reason
            ]);

            return true;
        });
    }

    /**
     * Get transaction summary for a user.
     */
    public function getUserTransactionSummary(User $user, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = Transaction::where('initiated_by', $user->id);

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        $summary = $query->selectRaw('
            COUNT(*) as total_transactions,
            SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as cancelled_count,
            SUM(amount) as total_amount,
            SUM(fee) as total_fees
        ')->first();

        return [
            'total_transactions' => $summary->total_transactions ?? 0,
            'completed_count' => $summary->completed_count ?? 0,
            'failed_count' => $summary->failed_count ?? 0,
            'cancelled_count' => $summary->cancelled_count ?? 0,
            'total_amount' => $summary->total_amount ?? 0,
            'total_fees' => $summary->total_fees ?? 0,
            'success_rate' => $summary->total_transactions > 0
                ? round(($summary->completed_count / $summary->total_transactions) * 100, 2)
                : 0
        ];
    }
}
