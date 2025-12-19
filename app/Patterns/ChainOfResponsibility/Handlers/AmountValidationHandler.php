<?php

namespace App\Patterns\ChainOfResponsibility\Handlers;

use App\Models\Transaction;
use App\Models\Account;
use App\Enums\TransactionType;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\InvalidAmountException;
use App\Patterns\ChainOfResponsibility\Interfaces\TransactionHandler;
use Illuminate\Support\Facades\Log;

class AmountValidationHandler implements TransactionHandler
{
    private ?TransactionHandler $next = null;

    public function setNext(TransactionHandler $handler): TransactionHandler
    {
        $this->next = $handler;
        return $handler;
    }

    public function handle(Transaction $transaction): bool
    {
        Log::debug('AmountValidationHandler: Validating transaction amount', [
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
            'fee' => $transaction->fee,
            'type' => $transaction->type->value
        ]);

        try {
            $this->validateAmount($transaction);
            $this->validateBalance($transaction);

            Log::debug('AmountValidationHandler: Amount validation passed', [
                'transaction_id' => $transaction->id
            ]);

            return $this->next ? $this->next->handle($transaction) : true;

        } catch (InsufficientBalanceException $e) {
            // For insufficient balance, we don't require approval - it's a hard failure
            Log::warning('AmountValidationHandler: Insufficient balance', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (InvalidAmountException $e) {
            Log::warning('AmountValidationHandler: Invalid amount', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('AmountValidationHandler: Unexpected error', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw $e;
        }
    }

    private function validateAmount(Transaction $transaction): void
    {
        // Validate amount is positive
        if ($transaction->amount <= 0) {
            throw new InvalidAmountException('Transaction amount must be greater than zero');
        }

        // Validate amount doesn't exceed system limits
        $maxAmount = $this->getMaxTransactionAmount($transaction);
        if ($transaction->amount > $maxAmount) {
            throw new InvalidAmountException(
                sprintf('Transaction amount exceeds maximum limit of %.2f %s', $maxAmount, $transaction->currency)
            );
        }

        // Validate fee is reasonable
        if ($transaction->fee > $transaction->amount * 0.1) { // 10% fee cap
            Log::warning('High fee detected', [
                'transaction_id' => $transaction->id,
                'amount' => $transaction->amount,
                'fee' => $transaction->fee,
                'fee_percentage' => ($transaction->fee / $transaction->amount) * 100
            ]);
        }
    }

    private function validateBalance(Transaction $transaction): void
    {
        if (!$transaction->from_account_id) {
            return; // No balance check needed for deposits
        }

        $account = Account::with('currentState')->findOrFail($transaction->from_account_id);

        // Get available balance considering account state and features
        $availableBalance = $account->getAvailableBalance();
        $requiredAmount = $transaction->amount + $transaction->fee;

        if ($availableBalance < $requiredAmount) {
            throw new InsufficientBalanceException(
                sprintf('Insufficient balance. Available: %.2f %s, Required: %.2f %s',
                    $availableBalance,
                    $transaction->currency,
                    $requiredAmount,
                    $transaction->currency
                )
            );
        }

        // Check if this would cause overdraft
        $wouldOverdraft = ($availableBalance - $requiredAmount) < 0;

        if ($wouldOverdraft && !$account->hasFeature('overdraft_protection')) {
            Log::warning('Potential overdraft detected', [
                'account_id' => $account->id,
                'available_balance' => $availableBalance,
                'required_amount' => $requiredAmount,
                'transaction_id' => $transaction->id
            ]);
        }
    }

    private function getMaxTransactionAmount(Transaction $transaction): float
    {
        return match($transaction->type) {
            TransactionType::DEPOSIT => 1000000.00, // $1M max deposit
            TransactionType::WITHDRAWAL => 100000.00, // $100K max withdrawal
            TransactionType::TRANSFER => 500000.00, // $500K max transfer
            TransactionType::INTERNATIONAL_TRANSFER => 250000.00, // $250K max international
            default => 100000.00
        };
    }

    public function getName(): string
    {
        return 'AmountValidationHandler';
    }

    public function getPriority(): int
    {
        return 10; // High priority - should be one of the first checks
    }
}
