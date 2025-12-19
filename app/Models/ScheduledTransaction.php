<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;
use App\Exceptions\ScheduledTransactionException;

class ScheduledTransaction extends Model
{
    use SoftDeletes, HasFactory;

    protected $fillable = [
        'transaction_id', 'frequency', 'next_execution',
        'execution_count', 'max_executions', 'is_active'
    ];

    protected $casts = [
        'next_execution' => 'datetime',
        'is_active' => 'boolean',
        'execution_count' => 'integer',
        'max_executions' => 'integer'
    ];

    protected $dates = ['next_execution'];

    // Frequency constants
    const FREQUENCIES = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'yearly' => 'Yearly'
    ];

    const MAX_EXECUTIONS_LIMIT = 1000;

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($scheduled) {
            $scheduled->validateScheduledTransaction();
        });

        static::updating(function ($scheduled) {
            $scheduled->validateScheduledTransaction();
        });
    }

    /**
     * Validate scheduled transaction data.
     */
    public function validateScheduledTransaction(): void
    {
        // Validate frequency
        if (!array_key_exists($this->frequency, self::FREQUENCIES)) {
            throw new ScheduledTransactionException("Invalid frequency. Must be one of: " . implode(', ', array_keys(self::FREQUENCIES)));
        }

        // Validate next execution date
        if ($this->next_execution && $this->next_execution->isPast()) {
            throw new ScheduledTransactionException("Next execution date cannot be in the past");
        }

        // Validate execution counts
        if ($this->max_executions && $this->max_executions > self::MAX_EXECUTIONS_LIMIT) {
            throw new ScheduledTransactionException("Maximum executions cannot exceed " . self::MAX_EXECUTIONS_LIMIT);
        }

        if ($this->execution_count > ($this->max_executions ?? self::MAX_EXECUTIONS_LIMIT)) {
            throw new ScheduledTransactionException("Execution count cannot exceed maximum executions");
        }
    }

    /**
     * Relationships
     */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Scopes
     */
    public function scopeDue($query)
    {
        return $query->where('next_execution', '<=', now())
            ->where('is_active', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Business logic methods
     */
    public function isDue(): bool
    {
        return $this->is_active && $this->next_execution && $this->next_execution->isPast() && now()->diffInMinutes($this->next_execution) <= 5;
    }

    public function canBeExecuted(): bool
    {
        return $this->is_active &&
            (!$this->max_executions || $this->execution_count < $this->max_executions) &&
            $this->transaction->status !== TransactionStatus::CANCELLED;
    }

    public function getNextExecutionDate(): ?Carbon
    {
        if (!$this->next_execution) {
            return null;
        }

        return match($this->frequency) {
            'daily' => $this->next_execution->copy()->addDay(),
            'weekly' => $this->next_execution->copy()->addWeek(),
            'monthly' => $this->next_execution->copy()->addMonth(),
            'yearly' => $this->next_execution->copy()->addYear(),
            default => $this->next_execution->copy()->addDay()
        };
    }

    public function markAsExecuted(): void
    {
        $this->execution_count++;
        $this->next_execution = $this->getNextExecutionDate();

        // Check if we've reached max executions
        if ($this->max_executions && $this->execution_count >= $this->max_executions) {
            $this->is_active = false;
        }

        $this->save();
    }

    public function deactivate(): void
    {
        $this->is_active = false;
        $this->save();
    }

    public function getScheduleDetails(): array
    {
        return [
            'id' => $this->id,
            'frequency' => $this->frequency,
            'frequency_label' => self::FREQUENCIES[$this->frequency] ?? $this->frequency,
            'next_execution' => $this->next_execution ? $this->next_execution->format('Y-m-d H:i:s') : null,
            'execution_count' => $this->execution_count,
            'max_executions' => $this->max_executions,
            'is_active' => $this->is_active,
            'remaining_executions' => $this->max_executions ? max(0, $this->max_executions - $this->execution_count) : 'Unlimited'
        ];
    }

    /**
     * Create a new scheduled transaction
     */
    public static function createFromTransaction(Transaction $transaction, array $scheduleData): self
    {
        return self::create([
            'transaction_id' => $transaction->id,
            'frequency' => $scheduleData['frequency'],
            'next_execution' => $scheduleData['start_date'] ?? now()->addDay(),
            'execution_count' => 0,
            'max_executions' => $scheduleData['max_executions'] ?? null,
            'is_active' => true
        ]);
    }
}
