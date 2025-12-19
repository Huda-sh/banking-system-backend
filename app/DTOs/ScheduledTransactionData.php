<?php

namespace App\DTOs;

use Spatie\DataTransferObject\DataTransferObject;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class ScheduledTransactionData extends DataTransferObject
{
    public function __construct(
        public TransactionData $transaction_data,
        public string $frequency,
        public ?Carbon $start_date = null,
        public ?int $max_executions = null,
        public ?int $execution_count = 0,
        public bool $is_active = true
    ) {
        $this->start_date = $this->start_date ?? now()->addDay();
        $this->validate();
    }

    private function validate(): void
    {
        $validator = Validator::make([
            'frequency' => $this->frequency,
            'start_date' => $this->start_date,
            'max_executions' => $this->max_executions,
            'execution_count' => $this->execution_count
        ], [
            'frequency' => 'required|in:daily,weekly,monthly,yearly',
            'start_date' => 'required|date|after:now',
            'max_executions' => 'nullable|integer|min:1|max:1000',
            'execution_count' => 'required|integer|min:0'
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Validate max executions against execution count
        if ($this->max_executions && $this->execution_count >= $this->max_executions) {
            $this->is_active = false;
        }

        // Validate frequency-specific rules
        $this->validateFrequencyRules();
    }

    private function validateFrequencyRules(): void
    {
        $amount = $this->transaction_data->amount;

        switch ($this->frequency) {
            case 'daily':
                if ($amount > 10000) {
                    throw new ValidationException(Validator::make([], []), 'Daily scheduled transactions cannot exceed $10,000');
                }
                break;

            case 'weekly':
                if ($amount > 50000) {
                    throw new ValidationException(Validator::make([], []), 'Weekly scheduled transactions cannot exceed $50,000');
                }
                break;

            case 'monthly':
                if ($amount > 100000) {
                    throw new ValidationException(Validator::make([], []), 'Monthly scheduled transactions cannot exceed $100,000');
                }
                break;

            case 'yearly':
                if ($amount > 1000000) {
                    throw new ValidationException(Validator::make([], []), 'Yearly scheduled transactions cannot exceed $1,000,000');
                }
                break;
        }
    }

    public function toArray(): array
    {
        return [
            'transaction_data' => $this->transaction_data->toArray(),
            'frequency' => $this->frequency,
            'start_date' => $this->start_date->format('Y-m-d H:i:s'),
            'max_executions' => $this->max_executions,
            'execution_count' => $this->execution_count,
            'is_active' => $this->is_active,
            'next_execution' => $this->getNextExecutionDate()?->format('Y-m-d H:i:s')
        ];
    }

    public function getNextExecutionDate(): ?Carbon
    {
        if (!$this->is_active || ($this->max_executions && $this->execution_count >= $this->max_executions)) {
            return null;
        }

        return match($this->frequency) {
            'daily' => $this->start_date->copy()->addDay(),
            'weekly' => $this->start_date->copy()->addWeek(),
            'monthly' => $this->start_date->copy()->addMonth(),
            'yearly' => $this->start_date->copy()->addYear(),
            default => $this->start_date->copy()->addDay()
        };
    }

    public function calculateTotalProjectedAmount(): float
    {
        if (!$this->max_executions) {
            return $this->transaction_data->amount * 12; // Assume 12 months projection
        }

        return $this->transaction_data->amount * $this->max_executions;
    }

    public function getScheduleSummary(): array
    {
        return [
            'frequency_label' => $this->getFrequencyLabel(),
            'start_date' => $this->start_date->format('Y-m-d H:i:s'),
            'max_executions' => $this->max_executions ?? 'Unlimited',
            'execution_count' => $this->execution_count,
            'remaining_executions' => $this->max_executions
                ? max(0, $this->max_executions - $this->execution_count)
                : 'Unlimited',
            'is_active' => $this->is_active,
            'next_execution' => $this->getNextExecutionDate()?->format('Y-m-d H:i:s'),
            'total_projected_amount' => $this->calculateTotalProjectedAmount()
        ];
    }

    private function getFrequencyLabel(): string
    {
        return match($this->frequency) {
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            'yearly' => 'Yearly',
            default => 'Unknown'
        };
    }
}
