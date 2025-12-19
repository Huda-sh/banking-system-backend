<?php

namespace App\Patterns\Command\Commands;

use App\Models\Account;
use App\Models\Transaction;
use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use App\Patterns\Command\Interfaces\TransactionCommand;
use App\Exceptions\CommandException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\AccountStateException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DepositCommand implements TransactionCommand
{
    private array $data;
    private ?Transaction $transaction = null;
    private array $context = [];

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->validate();
    }

    public function execute(): bool
    {
        Log::debug('DepositCommand: Executing deposit transaction', [
            'account_id' => $this->data['to_account_id'],
            'amount' => $this->data['amount']
        ]);

        return DB::transaction(function () {
            // Get the target account
            $toAccount = Account::with('currentState')->findOrFail($this->data['to_account_id']);

            // Validate account state
            if (!$toAccount->currentState->canReceiveDeposits()) {
                throw new AccountStateException(
                    "Account {$toAccount->account_number} cannot receive deposits in current state: {$toAccount->currentState->state}"
                );
            }

            // Create transaction record
            $this->transaction = Transaction::create([
                'from_account_id' => null, // No source account for deposits
                'to_account_id' => $toAccount->id,
                'amount' => $this->data['amount'],
                'currency' => $this->data['currency'] ?? $toAccount->currency,
                'type' => TransactionType::DEPOSIT,
                'status' => TransactionStatus::PROCESSING,
                'fee' => $this->data['fee'] ?? 0.00,
                'initiated_by' => $this->data['user_id'],
                'description' => $this->data['description'] ?? 'Deposit transaction',
                'ip_address' => $this->context['ip_address'] ?? request()->ip() ?? 'system',
                'metadata' => array_merge($this->data['metadata'] ?? [], [
                    'command' => $this->getName(),
                    'execution_time' => now()->format('Y-m-d H:i:s')
                ])
            ]);

            // Update account balance
            $toAccount->increment('balance', $this->data['amount']);

            // Update transaction status
            $this->transaction->update([
                'status' => TransactionStatus::COMPLETED,
                'processed_by' => $this->data['user_id'],
                'approved_at' => now()
            ]);

            Log::info('DepositCommand: Deposit executed successfully', [
                'transaction_id' => $this->transaction->id,
                'account_id' => $toAccount->id,
                'amount' => $this->data['amount']
            ]);

            return true;
        });
    }

    public function undo(): bool
    {
        if (!$this->transaction || $this->transaction->status !== TransactionStatus::COMPLETED) {
            throw new CommandException('Cannot undo a deposit that was not completed');
        }

        Log::debug('DepositCommand: Undoing deposit transaction', [
            'transaction_id' => $this->transaction->id,
            'account_id' => $this->transaction->to_account_id,
            'amount' => $this->transaction->amount
        ]);

        return DB::transaction(function () {
            $toAccount = Account::findOrFail($this->transaction->to_account_id);

            // Check if account has sufficient balance to reverse the deposit
            if ($toAccount->balance < $this->transaction->amount) {
                throw new InsufficientBalanceException(
                    "Account {$toAccount->account_number} has insufficient balance to reverse deposit"
                );
            }

            // Reverse the deposit
            $toAccount->decrement('balance', $this->transaction->amount);

            // Update transaction status
            $this->transaction->update([
                'status' => TransactionStatus::REVERSED,
                'metadata' => array_merge($this->transaction->metadata ?? [], [
                    'reversed_at' => now()->format('Y-m-d H:i:s'),
                    'reversed_by' => $this->data['user_id'],
                    'reversal_reason' => $this->context['reversal_reason'] ?? 'Command undo'
                ])
            ]);

            Log::warning('DepositCommand: Deposit reversed successfully', [
                'transaction_id' => $this->transaction->id,
                'account_id' => $toAccount->id,
                'amount' => $this->transaction->amount
            ]);

            return true;
        });
    }

    public function getTransaction(): Transaction
    {
        if (!$this->transaction) {
            throw new CommandException('Transaction not created yet. Execute command first.');
        }

        return $this->transaction;
    }

    public function validate(): bool
    {
        // Validate required parameters
        if (!isset($this->data['to_account_id'])) {
            throw new CommandException('to_account_id is required for deposit');
        }

        if (!isset($this->data['amount']) || $this->data['amount'] <= 0) {
            throw new CommandException('Valid amount is required for deposit');
        }

        if (!isset($this->data['user_id'])) {
            throw new CommandException('user_id is required for deposit');
        }

        // Validate account exists
        $toAccount = Account::find($this->data['to_account_id']);
        if (!$toAccount) {
            throw new CommandException("Account {$this->data['to_account_id']} not found");
        }

        // Validate currency
        $currency = $this->data['currency'] ?? $toAccount->currency;
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new CommandException("Invalid currency format: {$currency}");
        }

        // Validate amount limits
        $maxDeposit = config('banking.max_deposit_amount', 1000000.00);
        if ($this->data['amount'] > $maxDeposit) {
            throw new CommandException("Deposit amount exceeds maximum limit of {$maxDeposit}");
        }

        return true;
    }

    public function getName(): string
    {
        return 'DepositCommand';
    }

    public function getMetadata(): array
    {
        return [
            'command' => $this->getName(),
            'amount' => $this->data['amount'],
            'to_account_id' => $this->data['to_account_id'],
            'currency' => $this->data['currency'] ?? 'USD',
            'created_at' => now()->format('Y-m-d H:i:s')
        ];
    }

    public function setContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    public function canExecute(): bool
    {
        try {
            return $this->validate();
        } catch (CommandException $e) {
            return false;
        }
    }

    public function canUndo(): bool
    {
        return $this->transaction && $this->transaction->status === TransactionStatus::COMPLETED;
    }
}
