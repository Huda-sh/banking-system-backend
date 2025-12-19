<?php

namespace App\Patterns\Decorator\Processors;

use App\Models\Transaction;
use App\Models\Account;
use App\Models\User;
use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use App\Exceptions\ProcessorException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\AccountStateException;
use App\Patterns\Decorator\Interfaces\TransactionProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BaseTransactionProcessor implements TransactionProcessor
{
    protected array $context = [];
    protected bool $enabled = true;
    protected array $metadata = [
        'processor_version' => '1.0.0',
        'last_processed' => null,
        'total_processed' => 0,
        'success_rate' => 0
    ];

    public function process(Transaction $transaction): bool
    {
        if (!$this->isEnabled()) {
            throw new ProcessorException('Base transaction processor is disabled');
        }

        try {
            Log::debug('BaseTransactionProcessor: Starting transaction processing', [
                'transaction_id' => $transaction->id,
                'type' => $transaction->type->value,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency
            ]);

            // Begin database transaction
            return DB::transaction(function () use ($transaction) {
                // Validate transaction first
                $this->validate($transaction);

                // Execute the transaction based on type
                $result = match($transaction->type) {
                    TransactionType::DEPOSIT => $this->processDeposit($transaction),
                    TransactionType::WITHDRAWAL => $this->processWithdrawal($transaction),
                    TransactionType::TRANSFER => $this->processTransfer($transaction),
                    TransactionType::SCHEDULED => $this->processScheduled($transaction),
                    default => $this->processGeneric($transaction)
                };

                // Update transaction status
                $transaction->update([
                    'status' => TransactionStatus::COMPLETED,
                    'processed_by' => $transaction->initiated_by,
                    'approved_at' => now()
                ]);

                // Update metadata
                $this->metadata['last_processed'] = now()->format('Y-m-d H:i:s');
                $this->metadata['total_processed']++;

                Log::info('BaseTransactionProcessor: Transaction processed successfully', [
                    'transaction_id' => $transaction->id,
                    'type' => $transaction->type->value,
                    'amount' => $transaction->amount
                ]);

                return $result;
            });

        } catch (\Exception $e) {
            Log::error('BaseTransactionProcessor: Transaction processing failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);

            throw new ProcessorException(
                "Transaction processing failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    public function validate(Transaction $transaction): bool
    {
        // Basic validation
        if ($transaction->amount <= 0) {
            throw new ProcessorException('Transaction amount must be greater than zero');
        }

        if (!preg_match('/^[A-Z]{3}$/', $transaction->currency)) {
            throw new ProcessorException('Invalid currency format');
        }

        // Validate account states
        $this->validateAccountStates($transaction);

        // Validate balances
        $this->validateBalances($transaction);

        return true;
    }

    private function validateAccountStates(Transaction $transaction): void
    {
        if ($transaction->from_account_id) {
            $fromAccount = Account::with('currentState')->findOrFail($transaction->from_account_id);
            if (!$fromAccount->currentState->canPerformOperation('withdraw')) {
                throw new AccountStateException('Source account cannot perform withdrawals in current state');
            }
        }

        $toAccount = Account::with('currentState')->findOrFail($transaction->to_account_id);
        if (!$toAccount->currentState->canPerformOperation('deposit')) {
            throw new AccountStateException('Destination account cannot receive deposits in current state');
        }
    }

    private function validateBalances(Transaction $transaction): void
    {
        if (!$transaction->from_account_id) {
            return; // No balance validation needed for pure deposits
        }

        $fromAccount = Account::findOrFail($transaction->from_account_id);
        $availableBalance = $fromAccount->getAvailableBalance();
        $requiredAmount = $transaction->amount + $transaction->fee;

        if ($availableBalance < $requiredAmount) {
            throw new InsufficientBalanceException(
                sprintf('Insufficient balance. Available: %.2f, Required: %.2f',
                    $availableBalance,
                    $requiredAmount
                )
            );
        }
    }

    private function processDeposit(Transaction $transaction): bool
    {
        $toAccount = Account::findOrFail($transaction->to_account_id);
        $toAccount->increment('balance', $transaction->amount);
        return true;
    }

    private function processWithdrawal(Transaction $transaction): bool
    {
        $fromAccount = Account::findOrFail($transaction->from_account_id);
        $totalAmount = $transaction->amount + $transaction->fee;
        $fromAccount->decrement('balance', $totalAmount);
        return true;
    }

    private function processTransfer(Transaction $transaction): bool
    {
        $fromAccount = Account::findOrFail($transaction->from_account_id);
        $toAccount = Account::findOrFail($transaction->to_account_id);

        $totalAmount = $transaction->amount + $transaction->fee;
        $fromAccount->decrement('balance', $totalAmount);
        $toAccount->increment('balance', $transaction->amount);

        return true;
    }

    private function processScheduled(Transaction $transaction): bool
    {
        // Scheduled transactions are handled by the scheduler service
        // This is just a placeholder for the base processor
        Log::info('BaseTransactionProcessor: Scheduled transaction processed', [
            'transaction_id' => $transaction->id
        ]);
        return true;
    }

    private function processGeneric(Transaction $transaction): bool
    {
        // Generic processing for other transaction types
        Log::info('BaseTransactionProcessor: Generic transaction processed', [
            'transaction_id' => $transaction->id,
            'type' => $transaction->type->value
        ]);
        return true;
    }

    public function getName(): string
    {
        return 'BaseTransactionProcessor';
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && config('banking.processors.base.enabled', true);
    }
}
