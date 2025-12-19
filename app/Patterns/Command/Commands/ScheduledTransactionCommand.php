<?php

namespace App\Patterns\Command\Commands;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\ScheduledTransaction;
use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use App\Patterns\Command\Interfaces\TransactionCommand;
use App\Exceptions\CommandException;
use App\Exceptions\ScheduledTransactionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ScheduledTransactionCommand implements TransactionCommand
{
    private array $data;
    private ?Transaction $transaction = null;
    private ?ScheduledTransaction $scheduledTransaction = null;
    private array $context = [];

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->validate();
    }

    public function execute(): bool
    {
        Log::debug('ScheduledTransactionCommand: Executing scheduled transaction', [
            'frequency' => $this->data['frequency'],
            'start_date' => $this->data['start_date'] ?? now()->addDay()->format('Y-m-d H:i:s')
        ]);

        return DB::transaction(function () {
            // Create the base transaction first
            $transactionData = array_merge($this->data, [
                'status' => TransactionStatus::SCHEDULED,
                'type' => TransactionType::SCHEDULED,
                'fee' => $this->data['fee'] ?? 0.00,
                'description' => $this->data['description'] ?? 'Scheduled transaction',
                'ip_address' => $this->context['ip_address'] ?? request()->ip() ?? 'system',
                'metadata' => array_merge($this->data['metadata'] ?? [], [
                    'command' => $this->getName(),
                    'scheduled' => true,
                    'frequency' => $this->data['frequency'],
                    'start_date' => $this->data['start_date'] ?? now()->addDay()->format('Y-m-d H:i:s')
                ])
            ]);

            // Determine the appropriate command based on transaction type
            $command = $this->getBaseCommand($transactionData);

            // Execute the base command to create the transaction
            $command->setContext($this->context);
            $command->execute();

            // Get the created transaction
            $this->transaction = $command->getTransaction();

            // Create the scheduled transaction record
            $this->scheduledTransaction = ScheduledTransaction::create([
                'transaction_id' => $this->transaction->id,
                'frequency' => $this->data['frequency'],
                'next_execution' => $this->data['start_date'] ?? now()->addDay(),
                'execution_count' => 0,
                'max_executions' => $this->data['max_executions'] ?? null,
                'is_active' => true
            ]);

            Log::info('ScheduledTransactionCommand: Scheduled transaction created successfully', [
                'transaction_id' => $this->transaction->id,
                'scheduled_id' => $this->scheduledTransaction->id,
                'frequency' => $this->data['frequency'],
                'next_execution' => $this->scheduledTransaction->next_execution?->format('Y-m-d H:i:s')
            ]);

            return true;
        });
    }

    private function getBaseCommand(array $transactionData): TransactionCommand
    {
        return match($transactionData['type']) {
            TransactionType::DEPOSIT => new DepositCommand($transactionData),
            TransactionType::WITHDRAWAL => new WithdrawalCommand($transactionData),
            TransactionType::TRANSFER => new TransferCommand($transactionData),
            default => throw new CommandException("Unsupported transaction type for scheduling: {$transactionData['type']}")
        };
    }

    public function undo(): bool
    {
        if (!$this->scheduledTransaction) {
            throw new CommandException('Cannot undo a scheduled transaction that was not created');
        }

        Log::debug('ScheduledTransactionCommand: Undoing scheduled transaction', [
            'scheduled_id' => $this->scheduledTransaction->id,
            'transaction_id' => $this->transaction?->id
        ]);

        return DB::transaction(function () {
            // Deactivate the schedule
            $this->scheduledTransaction->update([
                'is_active' => false,
                'metadata' => array_merge($this->scheduledTransaction->metadata ?? [], [
                    'undone_at' => now()->format('Y-m-d H:i:s'),
                    'undone_by' => $this->data['user_id'],
                    'undo_reason' => $this->context['undo_reason'] ?? 'Command undo'
                ])
            ]);

            // Cancel the base transaction if it exists
            if ($this->transaction) {
                $this->transaction->update([
                    'status' => TransactionStatus::CANCELLED,
                    'metadata' => array_merge($this->transaction->metadata ?? [], [
                        'cancelled_at' => now()->format('Y-m-d H:i:s'),
                        'cancelled_by' => $this->data['user_id'],
                        'cancellation_reason' => 'Scheduled transaction undone'
                    ])
                ]);

                // If the transaction was already executed, we need to reverse it
                if ($this->transaction->status === TransactionStatus::COMPLETED) {
                    $command = $this->getBaseCommand($this->transaction->toArray());
                    $command->setContext($this->context);
                    $command->undo();
                }
            }

            Log::warning('ScheduledTransactionCommand: Scheduled transaction undone successfully', [
                'scheduled_id' => $this->scheduledTransaction->id,
                'transaction_id' => $this->transaction?->id
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
        // Validate required parameters for scheduling
        if (!isset($this->data['frequency']) || !in_array($this->data['frequency'], ['daily', 'weekly', 'monthly', 'yearly'])) {
            throw new CommandException('Valid frequency is required for scheduled transaction (daily, weekly, monthly, yearly)');
        }

        if (!isset($this->data['user_id'])) {
            throw new CommandException('user_id is required for scheduled transaction');
        }

        // Validate start date
        $startDate = $this->data['start_date'] ?? now()->addDay();
        if (is_string($startDate)) {
            $startDate = Carbon::parse($startDate);
        }

        if ($startDate->isPast()) {
            throw new CommandException('Start date cannot be in the past');
        }

        // Validate max executions
        if (isset($this->data['max_executions']) && $this->data['max_executions'] <= 0) {
            throw new CommandException('max_executions must be greater than 0 if specified');
        }

        // Validate base transaction data
        $baseCommand = $this->getBaseCommand($this->data);
        $baseCommand->validate();

        // Additional validation for recurring transactions
        $this->validateRecurringRules();

        return true;
    }

    private function validateRecurringRules(): void
    {
        // Validate frequency-specific rules
        $amount = $this->data['amount'] ?? 0;
        $frequency = $this->data['frequency'];

        switch ($frequency) {
            case 'daily':
                if ($amount > 10000) {
                    throw new CommandException('Daily scheduled transactions cannot exceed $10,000');
                }
                break;

            case 'weekly':
                if ($amount > 50000) {
                    throw new CommandException('Weekly scheduled transactions cannot exceed $50,000');
                }
                break;

            case 'monthly':
                if ($amount > 100000) {
                    throw new CommandException('Monthly scheduled transactions cannot exceed $100,000');
                }
                break;

            case 'yearly':
                if ($amount > 1000000) {
                    throw new CommandException('Yearly scheduled transactions cannot exceed $1,000,000');
                }
                break;
        }

        // Check if user already has too many active scheduled transactions
        $activeSchedules = ScheduledTransaction::whereHas('transaction', function ($q) {
            $q->where('initiated_by', $this->data['user_id']);
        })->where('is_active', true)->count();

        $maxSchedules = config('banking.max_active_schedules', 25);
        if ($activeSchedules >= $maxSchedules) {
            throw new CommandException("User has reached maximum active scheduled transactions limit ({$maxSchedules})");
        }
    }

    public function getName(): string
    {
        return 'ScheduledTransactionCommand';
    }

    public function getMetadata(): array
    {
        return [
            'command' => $this->getName(),
            'frequency' => $this->data['frequency'],
            'start_date' => $this->data['start_date'] ?? now()->addDay()->format('Y-m-d H:i:s'),
            'max_executions' => $this->data['max_executions'] ?? 'Unlimited',
            'base_type' => $this->data['type'] ?? 'transfer',
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
        return $this->scheduledTransaction !== null;
    }

    /**
     * Get the scheduled transaction model.
     */
    public function getScheduledTransaction(): ?ScheduledTransaction
    {
        return $this->scheduledTransaction;
    }

    /**
     * Execute the next instance of the scheduled transaction.
     */
    public function executeNextInstance(): bool
    {
        if (!$this->scheduledTransaction || !$this->scheduledTransaction->is_active) {
            throw new CommandException('Cannot execute inactive scheduled transaction');
        }

        if (!$this->scheduledTransaction->isDue()) {
            throw new CommandException('Scheduled transaction is not due for execution');
        }

        Log::debug('ScheduledTransactionCommand: Executing next instance', [
            'scheduled_id' => $this->scheduledTransaction->id,
            'execution_count' => $this->scheduledTransaction->execution_count + 1
        ]);

        return DB::transaction(function () {
            // Update the schedule first to prevent duplicate execution
            $this->scheduledTransaction->execution_count++;
            $this->scheduledTransaction->next_execution = $this->scheduledTransaction->getNextExecutionDate();
            $this->scheduledTransaction->save();

            // Create a new transaction for this instance
            $instanceData = array_merge($this->data, [
                'status' => TransactionStatus::PROCESSING,
                'type' => $this->data['type'],
                'description' => $this->getScheduledDescription(),
                'metadata' => array_merge($this->data['metadata'] ?? [], [
                    'scheduled_instance' => true,
                    'scheduled_id' => $this->scheduledTransaction->id,
                    'instance_number' => $this->scheduledTransaction->execution_count,
                    'original_transaction_id' => $this->transaction->id
                ])
            ]);

            // Execute the base command for this instance
            $command = $this->getBaseCommand($instanceData);
            $command->setContext($this->context);
            $command->execute();

            // Get the instance transaction
            $instanceTransaction = $command->getTransaction();

            // Update schedule status if max executions reached
            if ($this->scheduledTransaction->max_executions &&
                $this->scheduledTransaction->execution_count >= $this->scheduledTransaction->max_executions) {
                $this->scheduledTransaction->is_active = false;
                $this->scheduledTransaction->save();

                Log::info('ScheduledTransactionCommand: Schedule completed', [
                    'scheduled_id' => $this->scheduledTransaction->id,
                    'total_executions' => $this->scheduledTransaction->execution_count
                ]);
            }

            Log::info('ScheduledTransactionCommand: Instance executed successfully', [
                'scheduled_id' => $this->scheduledTransaction->id,
                'instance_transaction_id' => $instanceTransaction->id,
                'instance_number' => $this->scheduledTransaction->execution_count,
                'next_execution' => $this->scheduledTransaction->next_execution?->format('Y-m-d H:i:s')
            ]);

            return true;
        });
    }

    private function getScheduledDescription(): string
    {
        $baseDescription = $this->data['description'] ?? 'Scheduled transaction';
        $frequencyLabel = match($this->data['frequency']) {
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            'yearly' => 'Yearly',
            default => 'Recurring'
        };

        return sprintf("%s - %s #%d", $baseDescription, $frequencyLabel, $this->scheduledTransaction->execution_count + 1);
    }
}
