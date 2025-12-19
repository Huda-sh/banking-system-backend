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

class TransferCommand implements TransactionCommand
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
        Log::debug('TransferCommand: Executing transfer transaction', [
            'from_account_id' => $this->data['from_account_id'],
            'to_account_id' => $this->data['to_account_id'],
            'amount' => $this->data['amount']
        ]);

        return DB::transaction(function () {
            // Get source and destination accounts
            $fromAccount = Account::with('currentState')->findOrFail($this->data['from_account_id']);
            $toAccount = Account::with('currentState')->findOrFail($this->data['to_account_id']);

            // Validate account states
            if (!$fromAccount->currentState->canTransferFrom()) {
                throw new AccountStateException(
                    "Account {$fromAccount->account_number} cannot send transfers in current state: {$fromAccount->currentState->state}"
                );
            }

            if (!$toAccount->currentState->canTransferTo()) {
                throw new AccountStateException(
                    "Account {$toAccount->account_number} cannot receive transfers in current state: {$toAccount->currentState->state}"
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
                'to_account_id' => $toAccount->id,
                'amount' => $this->data['amount'],
                'currency' => $this->data['currency'] ?? $fromAccount->currency,
                'type' => TransactionType::TRANSFER,
                'status' => TransactionStatus::PROCESSING,
                'fee' => $this->data['fee'] ?? 0.00,
                'initiated_by' => $this->data['user_id'],
                'description' => $this->data['description'] ?? 'Transfer transaction',
                'ip_address' => $this->context['ip_address'] ?? request()->ip() ?? 'system',
                'metadata' => array_merge($this->data['metadata'] ?? [], [
                    'command' => $this->getName(),
                    'execution_time' => now()->format('Y-m-d H:i:s'),
                    'total_amount' => $totalAmount,
                    'is_domestic' => $fromAccount->currency === $toAccount->currency,
                    'is_international' => $fromAccount->currency !== $toAccount->currency
                ])
            ]);

            // Execute the transfer
            $fromAccount->decrement('balance', $totalAmount);
            $toAccount->increment('balance', $this->data['amount']);

            // Check for cross-currency transfer
            if ($fromAccount->currency !== $toAccount->currency) {
                $this->handleCurrencyConversion($fromAccount, $toAccount);
            }

            // Update transaction status
            $this->transaction->update([
                'status' => TransactionStatus::COMPLETED,
                'processed_by' => $this->data['user_id'],
                'approved_at' => now()
            ]);

            Log::info('TransferCommand: Transfer executed successfully', [
                'transaction_id' => $this->transaction->id,
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $this->data['amount'],
                'fee' => $this->data['fee'] ?? 0.00,
                'currency' => $this->data['currency'] ?? $fromAccount->currency
            ]);

            return true;
        });
    }

    private function handleCurrencyConversion(Account $fromAccount, Account $toAccount): void
    {
        // In production, this would integrate with a currency conversion service
        // For now, we'll log the conversion requirement
        Log::info('TransferCommand: Cross-currency transfer detected', [
            'from_currency' => $fromAccount->currency,
            'to_currency' => $toAccount->currency,
            'transaction_id' => $this->transaction->id
        ]);

        // Add conversion metadata
        $this->transaction->update([
            'metadata' => array_merge($this->transaction->metadata ?? [], [
                'conversion_required' => true,
                'conversion_timestamp' => now()->format('Y-m-d H:i:s'),
                'conversion_service' => 'external_api' // Placeholder for actual service
            ])
        ]);
    }

    public function undo(): bool
    {
        if (!$this->transaction || $this->transaction->status !== TransactionStatus::COMPLETED) {
            throw new CommandException('Cannot undo a transfer that was not completed');
        }

        Log::debug('TransferCommand: Undoing transfer transaction', [
            'transaction_id' => $this->transaction->id,
            'from_account_id' => $this->transaction->from_account_id,
            'to_account_id' => $this->transaction->to_account_id,
            'amount' => $this->transaction->amount
        ]);

        return DB::transaction(function () {
            $fromAccount = Account::findOrFail($this->transaction->from_account_id);
            $toAccount = Account::findOrFail($this->transaction->to_account_id);

            // Validate destination account has sufficient balance to reverse
            if ($toAccount->balance < $this->transaction->amount) {
                throw new InsufficientBalanceException(
                    "Account {$toAccount->account_number} has insufficient balance to reverse transfer"
                );
            }

            // Reverse the transfer
            $totalAmount = $this->transaction->amount + $this->transaction->fee;
            $toAccount->decrement('balance', $this->transaction->amount);
            $fromAccount->increment('balance', $totalAmount);

            // Update transaction status
            $this->transaction->update([
                'status' => TransactionStatus::REVERSED,
                'metadata' => array_merge($this->transaction->metadata ?? [], [
                    'reversed_at' => now()->format('Y-m-d H:i:s'),
                    'reversed_by' => $this->data['user_id'],
                    'reversal_reason' => $this->context['reversal_reason'] ?? 'Command undo',
                    'reversed_amount' => $totalAmount,
                    'conversion_reversed' => $this->transaction->metadata['conversion_required'] ?? false
                ])
            ]);

            Log::warning('TransferCommand: Transfer reversed successfully', [
                'transaction_id' => $this->transaction->id,
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
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
            throw new CommandException('from_account_id is required for transfer');
        }

        if (!isset($this->data['to_account_id'])) {
            throw new CommandException('to_account_id is required for transfer');
        }

        if ($this->data['from_account_id'] === $this->data['to_account_id']) {
            throw new CommandException('Cannot transfer between the same account');
        }

        if (!isset($this->data['amount']) || $this->data['amount'] <= 0) {
            throw new CommandException('Valid amount is required for transfer');
        }

        if (!isset($this->data['user_id'])) {
            throw new CommandException('user_id is required for transfer');
        }

        // Validate accounts exist
        $fromAccount = Account::find($this->data['from_account_id']);
        $toAccount = Account::find($this->data['to_account_id']);

        if (!$fromAccount) {
            throw new CommandException("Source account {$this->data['from_account_id']} not found");
        }

        if (!$toAccount) {
            throw new CommandException("Destination account {$this->data['to_account_id']} not found");
        }

        // Validate currencies
        $currency = $this->data['currency'] ?? $fromAccount->currency;

        if ($this->data['currency'] && $this->data['currency'] !== $fromAccount->currency) {
            throw new CommandException("Specified currency {$this->data['currency']} does not match source account currency {$fromAccount->currency}");
        }

        // For cross-currency transfers, validate the currency format
        if ($fromAccount->currency !== $toAccount->currency) {
            // This would typically require currency conversion validation
            Log::info('TransferCommand: Cross-currency transfer validation', [
                'from_currency' => $fromAccount->currency,
                'to_currency' => $toAccount->currency
            ]);
        }

        // Validate amount limits
        $maxTransfer = $fromAccount->isGroup() ? 1000000.00 : 500000.00; // Higher limit for group accounts
        if ($this->data['amount'] > $maxTransfer) {
            throw new CommandException("Transfer amount exceeds maximum limit of {$maxTransfer} for account type");
        }

        // Validate fee structure
        $fee = $this->data['fee'] ?? 0.00;
        if ($fee < 0) {
            throw new CommandException('Fee cannot be negative');
        }

        // Dynamic fee validation based on transfer type
        $maxFeePercentage = $fromAccount->currency === $toAccount->currency ? 0.05 : 0.1; // 5% domestic, 10% international
        if ($fee > $this->data['amount'] * $maxFeePercentage) {
            Log::warning('TransferCommand: High fee detected for transfer type', [
                'amount' => $this->data['amount'],
                'fee' => $fee,
                'fee_percentage' => ($fee / $this->data['amount']) * 100,
                'transfer_type' => $fromAccount->currency === $toAccount->currency ? 'domestic' : 'international'
            ]);
        }

        return true;
    }

    public function getName(): string
    {
        return 'TransferCommand';
    }

    public function getMetadata(): array
    {
        return [
            'command' => $this->getName(),
            'amount' => $this->data['amount'],
            'from_account_id' => $this->data['from_account_id'],
            'to_account_id' => $this->data['to_account_id'],
            'currency' => $this->data['currency'] ?? 'USD',
            'fee' => $this->data['fee'] ?? 0.00,
            'is_cross_currency' => ($this->data['currency'] ?? 'USD') !== 'USD', // Simplified check
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
