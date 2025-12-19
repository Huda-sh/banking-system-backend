<?php

namespace App\Patterns\ChainOfResponsibility\Handlers;

use App\Models\Transaction;
use App\Models\Account;
use App\Enums\TransactionType;
use App\Enums\AccountState;
use App\Exceptions\AccountStateException;
use App\Patterns\ChainOfResponsibility\Interfaces\TransactionHandler;
use Illuminate\Support\Facades\Log;

class AccountStateHandler implements TransactionHandler
{
    private ?TransactionHandler $next = null;

    public function setNext(TransactionHandler $handler): TransactionHandler
    {
        $this->next = $handler;
        return $handler;
    }

    public function handle(Transaction $transaction): bool
    {
        Log::debug('AccountStateHandler: Validating account states', [
            'transaction_id' => $transaction->id,
            'from_account_id' => $transaction->from_account_id,
            'to_account_id' => $transaction->to_account_id
        ]);

        try {
            $this->validateAccountStates($transaction);

            Log::debug('AccountStateHandler: Account state validation passed', [
                'transaction_id' => $transaction->id
            ]);

            return $this->next ? $this->next->handle($transaction) : true;

        } catch (AccountStateException $e) {
            Log::warning('AccountStateHandler: Account state validation failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('AccountStateHandler: Unexpected error', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw $e;
        }
    }

    private function validateAccountStates(Transaction $transaction): void
    {
        // Validate from account state if exists
        if ($transaction->from_account_id) {
            $fromAccount = Account::with('currentState')->findOrFail($transaction->from_account_id);
            $this->validateSourceAccountState($fromAccount, $transaction);
        }

        // Validate to account state
        $toAccount = Account::with('currentState')->findOrFail($transaction->to_account_id);
        $this->validateDestinationAccountState($toAccount, $transaction);
    }

    private function validateSourceAccountState(Account $account, Transaction $transaction): void
    {
        $currentState = $account->currentState->state;
        $stateEnum = AccountState::tryFrom($currentState);

        if (!$stateEnum) {
            throw new AccountStateException("Invalid account state: {$currentState}");
        }

        switch ($stateEnum) {
            case AccountState::ACTIVE:
                // Active accounts can perform most operations
                break;

            case AccountState::FROZEN:
                // Frozen accounts can only receive deposits
                if ($transaction->type !== TransactionType::DEPOSIT) {
                    throw new AccountStateException(
                        'Account is frozen. Only deposits are allowed.'
                    );
                }
                break;

            case AccountState::SUSPENDED:
                // Suspended accounts can only view balances
                throw new AccountStateException(
                    'Account is suspended. No transactions allowed.'
                );

            case AccountState::CLOSED:
                throw new AccountStateException(
                    'Account is closed. No transactions allowed.'
                );

            default:
                throw new AccountStateException(
                    "Account state {$currentState} does not allow transactions."
                );
        }

        // Additional validation based on transaction type
        if ($transaction->type === TransactionType::WITHDRAWAL) {
            if (!$account->currentState->canWithdraw()) {
                throw new AccountStateException(
                    'Account cannot process withdrawals in current state.'
                );
            }
        }

        if ($transaction->type === TransactionType::TRANSFER) {
            if (!$account->currentState->canTransferFrom()) {
                throw new AccountStateException(
                    'Account cannot send transfers in current state.'
                );
            }
        }
    }

    private function validateDestinationAccountState(Account $account, Transaction $transaction): void
    {
        $currentState = $account->currentState->state;
        $stateEnum = AccountState::tryFrom($currentState);

        if (!$stateEnum) {
            throw new AccountStateException("Invalid account state: {$currentState}");
        }

        switch ($stateEnum) {
            case AccountState::ACTIVE:
                // Active accounts can receive all types of transactions
                break;

            case AccountState::FROZEN:
                // Frozen accounts can only receive deposits and transfers
                if (!in_array($transaction->type, [TransactionType::DEPOSIT, TransactionType::TRANSFER])) {
                    throw new AccountStateException(
                        'Account is frozen. Only deposits and transfers are allowed.'
                    );
                }
                break;

            case AccountState::SUSPENDED:
                // Suspended accounts can only receive deposits
                if ($transaction->type !== TransactionType::DEPOSIT) {
                    throw new AccountStateException(
                        'Account is suspended. Only deposits are allowed.'
                    );
                }
                break;

            case AccountState::CLOSED:
                throw new AccountStateException(
                    'Account is closed. No transactions allowed.'
                );

            default:
                throw new AccountStateException(
                    "Account state {$currentState} cannot receive transactions."
                );
        }

        // Additional validation based on transaction type
        if ($transaction->type === TransactionType::DEPOSIT) {
            if (!$account->currentState->canReceiveDeposits()) {
                throw new AccountStateException(
                    'Account cannot receive deposits in current state.'
                );
            }
        }

        if ($transaction->type === TransactionType::TRANSFER) {
            if (!$account->currentState->canTransferTo()) {
                throw new AccountStateException(
                    'Account cannot receive transfers in current state.'
                );
            }
        }
    }

    public function getName(): string
    {
        return 'AccountStateHandler';
    }

    public function getPriority(): int
    {
        return 20; // High priority - account state is fundamental
    }
}
