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

class WithdrawalCommand implements TransactionCommand
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
        Log::debug('WithdrawalCommand: Executing withdrawal transaction', [
            'account_id' => $this->data['from_account_id'],
            'amount' => $this->data['amount']
        ]);

        return DB::transaction(function () {
            // Get the source account
            $fromAccount = Account::with('currentState')->findOrFail($this->data['from_account_id']);

            // Validate account state
            if (!$fromAccount->currentState->canWithdraw()) {
                throw new AccountStateException(
                    "Account {$fromAccount->account_number} cannot withdraw in current state: {$fromAccount->currentState->state}"
                );
            }

            // Calculate total amount including fees
            $totalAmount = $this->data['amount'] + ($this->data['fee'] ?? 0.00);

            // Check available balance
            $availableBalance = $fromAccount->getAvailableBalance();
            if ($availableBalance < $totalAmount) {
                throw new InsufficientBalanceException(
                    "Insufficient balance. Available: {$availableBalance}, Required: {$totalAmount}"
                );
            }

            // Create transaction record
            $this->transaction = Transaction::create([
                'from_account_id' => $fromAccount->id,
                'to_account_id' => null, // No destination account for withdrawals
                'amount' => $this->data['amount'],
                'currency' => $this->data['currency'] ?? $fromAccount->currency,
                'type' => TransactionType::WITHDRAWAL,
                'status' => TransactionStatus::PROCESSING,
                'fee' => $this->data['fee'] ?? 0.00,
                'initiated_by' => $this->data['user_id'],
                'description' => $this->data['description'] ?? 'Withdrawal transaction',
                'ip_address' => $this->context['ip_address'] ?? request()->ip() ?? 'system',
                'metadata' => array_merge($this->data['metadata'] ?? [], [
                    'command' => $this->getName(),
                    'execution_time' => now()->format('Y-m-d H:i:s'),
                    'total_amount' => $totalAmount
                ])
            ]);

            // Update account balance
            $fromAccount->decrement('balance', $totalAmount);

            // Check for overdraft
            if ($fromAccount->balance < 0 && !$fromAccount->hasFeature('overdraft_protection')) {
                Log::warning('WithdrawalCommand: Account went into overdraft', [
                    'account_id' => $fromAccount->id,
                    'balance' => $fromAccount->balance,
                    'transaction_id' => $this->transaction->id
                ]);
            }

            // Update transaction status
            $this->transaction->update([
                'status' => TransactionStatus::COMPLETED,
                'processed_by' => $this->data['user_id'],
                'approved_at' => now()
            ]);

            Log::info('WithdrawalCommand: Withdrawal executed successfully', [
                'transaction_id' => $this->transaction->id,
                'account_id' => $fromAccount->id,
                'amount' => $this->data['amount'],
                'fee' => $this->data['fee'] ?? 0.00
            ]);

            return true;
        });
    }

    public function undo(): bool
    {
        if (!$this->transaction || $this->transaction->status !== TransactionStatus::COMPLETED) {
            throw new CommandException('Cannot undo a withdrawal that was not completed');
        }

        Log::debug('WithdrawalCommand: Undoing withdrawal transaction', [
            'transaction_id' => $this->transaction->id,
            'account_id' => $this->transaction->from_account_id,
            'amount' => $this->transaction->amount
        ]);

        return DB::transaction(function () {
            $fromAccount = Account::findOrFail($this->transaction->from_account_id);

            // Reverse the withdrawal (add back the amount + fee)
            $totalAmount = $this->transaction->amount + $this->transaction->fee;
            $fromAccount->increment('balance', $totalAmount);

            // Update transaction status
            $this->transaction->update([
                'status' => TransactionStatus::REVERSED,
                'metadata' => array_merge($this->transaction->metadata ?? [], [
                    'reversed_at' => now()->format('Y-m-d H:i:s'),
                    'reversed_by' => $this->data['user_id'],
                    'reversal_reason' => $this->context['reversal_reason'] ?? 'Command undo',
                    'reversed_amount' => $totalAmount
                ])
            ]);

            Log::warning('WithdrawalCommand: Withdrawal reversed successfully', [
                'transaction_id' => $this->transaction->id,
                'account_id' => $fromAccount->id,
                'amount' => $totalAmount
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
        if (!isset($this->data['from_account_id'])) {
            throw new CommandException('from_account_id is required for withdrawal');
        }

        if (!isset($this->data['amount']) || $this->data['amount'] <= 0) {
            throw new CommandException('Valid amount is required for withdrawal');
        }

        if (!isset($this->data['user_id'])) {
            throw new CommandException('user_id is required for withdrawal');
        }

        // Validate account exists
        $fromAccount = Account::find($this->data['from_account_id']);
        if (!$fromAccount) {
            throw new CommandException("Account {$this->data['from_account_id']} not found");
        }

        // Validate currency
        $currency = $this->data['currency'] ?? $fromAccount->currency;
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new CommandException("Invalid currency format: {$currency}");
        }

        // Validate amount limits
        $maxWithdrawal = config('banking.max_withdrawal_amount', 100000.00);
        if ($this->data['amount'] > $maxWithdrawal) {
            throw new CommandException("Withdrawal amount exceeds maximum limit of {$maxWithdrawal}");
        }

        // Validate fee structure
        $fee = $this->data['fee'] ?? 0.00;
        if ($fee < 0) {
            throw new CommandException('Fee cannot be negative');
        }

        if ($fee > $this->data['amount'] * 0.1) { // 10% fee cap
            Log::warning('WithdrawalCommand: High fee detected', [
                'amount' => $this->data['amount'],
                'fee' => $fee,
                'fee_percentage' => ($fee / $this->data['amount']) * 100
            ]);
        }

        return true;
    }

    public function getName(): string
    {
        return 'WithdrawalCommand';
    }

    public function getMetadata(): array
    {
        return [
            'command' => $this->getName(),
            'amount' => $this->data['amount'],
            'from_account_id' => $this->data['from_account_id'],
            'currency' => $this->data['currency'] ?? 'USD',
            'fee' => $this->data['fee'] ?? 0.00,
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
