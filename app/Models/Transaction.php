<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use App\Enums\Direction;
use App\Models\Concerns\HasTransactionHistory;
use App\Models\Concerns\HasApprovalWorkflow;
use App\Exceptions\InvalidTransactionException;
use Carbon\Carbon;

class Transaction extends Model
{
    use SoftDeletes, HasFactory, HasTransactionHistory, HasApprovalWorkflow;

    protected $fillable = [
        'from_account_id', 'to_account_id', 'amount', 'currency',
        'type', 'status', 'direction', 'fee', 'initiated_by', 'processed_by',
        'approved_by', 'approved_at', 'description', 'ip_address', 'metadata'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'type' => TransactionType::class,
        'status' => TransactionStatus::class,
        'direction' => Direction::class,
        'approved_at' => 'datetime',
        'metadata' => 'array'
    ];

    protected $dates = ['approved_at'];

    protected $appends = ['net_amount', 'total_amount'];

    // Validation constants
    const MAX_AMOUNT = 99999999.99;
    const MIN_AMOUNT = 0.01;

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            $transaction->validateTransaction();
        });

        static::created(function ($transaction) {
            $transaction->logAudit('created');
        });

        static::updated(function ($transaction) {
            $transaction->logAudit('updated');
        });
    }

    /**
     * Validate transaction data before saving.
     */
    public function validateTransaction(): void
    {
        // Validate amount
        if ($this->amount < self::MIN_AMOUNT || $this->amount > self::MAX_AMOUNT) {
            throw new InvalidTransactionException(
                "Transaction amount must be between " . self::MIN_AMOUNT . " and " . self::MAX_AMOUNT
            );
        }

        // Validate currency format
        if (!preg_match('/^[A-Z]{3}$/', $this->currency)) {
            throw new InvalidTransactionException("Invalid currency format. Must be 3 uppercase letters.");
        }

        // Validate account relationships
        if ($this->type !== TransactionType::DEPOSIT && !$this->from_account_id) {
            throw new InvalidTransactionException("From account is required for non-deposit transactions");
        }

        if (!$this->to_account_id) {
            throw new InvalidTransactionException("To account is required");
        }
    }

    /**
     * Relationships
     */
    public function fromAccount()
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount()
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvals()
    {
        return $this->hasMany(TransactionApproval::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(TransactionAuditLog::class);
    }

    public function scheduledTransaction()
    {
        return $this->hasOne(ScheduledTransaction::class);
    }

    /**
     * Scopes
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', TransactionStatus::COMPLETED);
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', TransactionStatus::PENDING_APPROVAL);
    }

    public function scopeByAccount($query, $accountId)
    {
        return $query->where(function ($q) use ($accountId) {
            $q->where('from_account_id', $accountId)
                ->orWhere('to_account_id', $accountId);
        });
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Accessors
     */
    public function getNetAmountAttribute(): float
    {
        return $this->amount - $this->fee;
    }

    public function getTotalAmountAttribute(): float
    {
        return $this->amount + $this->fee;
    }

    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2) . ' ' . $this->currency;
    }

    public function getFormattedFeeAttribute(): string
    {
        return number_format($this->fee, 2) . ' ' . $this->currency;
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->status === TransactionStatus::COMPLETED;
    }

    public function getRequiresApprovalAttribute(): bool
    {
        return in_array($this->status, [
            TransactionStatus::PENDING_APPROVAL,
            TransactionStatus::PENDING
        ]);
    }

    /**
     * Business logic methods
     */
    public function isDeposit(): bool
    {
        return $this->type === TransactionType::DEPOSIT;
    }

    public function isWithdrawal(): bool
    {
        return $this->type === TransactionType::WITHDRAWAL;
    }

    public function isTransfer(): bool
    {
        return $this->type === TransactionType::TRANSFER;
    }

    public function isScheduled(): bool
    {
        return $this->type === TransactionType::SCHEDULED || $this->scheduledTransaction()->exists();
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            TransactionStatus::PENDING,
            TransactionStatus::PENDING_APPROVAL
        ]);
    }

    public function canBeReversed(): bool
    {
        return $this->status === TransactionStatus::COMPLETED && !$this->isScheduled();
    }

    public function getTransactionDetails(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'direction' => $this->direction?->value,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'net_amount' => $this->net_amount,
            'currency' => $this->currency,
            'from_account' => $this->fromAccount ? $this->fromAccount->account_number : null,
            'to_account' => $this->toAccount ? $this->toAccount->account_number : null,
            'initiated_by' => $this->initiatedBy ? $this->initiatedBy->full_name : null,
            'approved_at' => $this->approved_at ? $this->approved_at->format('Y-m-d H:i:s') : null,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'description' => $this->description
        ];
    }

    /**
     * Audit logging helper
     */
    private function logAudit(string $action): void
    {
        TransactionAuditLog::create([
            'transaction_id' => $this->id,
            'user_id' => auth()->id() ?? $this->initiated_by,
            'action' => $action,
            'ip_address' => request()->ip() ?? 'system',
            'old_data' => $action === 'updated' ? $this->getOriginal() : null,
            'new_data' => $this->toArray()
        ]);
    }

    /**
     * Calculate daily transaction limit for user
     */
    public static function getUserDailyTransactionTotal(User $user, Carbon $date): float
    {
        return self::where('initiated_by', $user->id)
            ->whereDate('created_at', $date)
            ->where('status', TransactionStatus::COMPLETED)
            ->sum('amount');
    }
    /**
     * Get the type label for display.
     */
    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'deposit' => 'Deposit',
            'withdrawal' => 'Withdrawal',
            'transfer' => 'Transfer',
            'scheduled' => 'Scheduled',
            'loan_payment' => 'Loan Payment',
            'interest_payment' => 'Interest Payment',
            'fee_charge' => 'Fee Charge',
            default => ucfirst($this->type)
        };
    }

    /**
     * Get the status label for display.
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Pending',
            'pending_approval' => 'Pending Approval',
            'approved' => 'Approved',
            'completed' => 'Completed',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            'reversed' => 'Reversed',
            default => ucfirst($this->status)
        };
    }
}
