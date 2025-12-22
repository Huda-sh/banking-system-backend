<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use App\Enums\Direction;
use App\Models\Concerns\HasTransactionHistory;
use App\Models\Concerns\HasApprovalWorkflow;
use App\Exceptions\InvalidTransactionException;
use Carbon\Carbon;
use App\Models\Deposit;
use App\Models\WithDrawal;
use App\Models\Transfer;
use App\Models\TransactionAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory, HasTransactionHistory, HasApprovalWorkflow;

    protected $fillable = [
        'reference_number',
        'description',
        'source_account_id',
        'target_account_id',
        'amount',
        'currency',
        'type',
        'status',
        'direction',
        'initiated_by',
        'processed_by',
    ];

    protected $dates = ['approved_at'];



    /**
     * Relationships
     */
     public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'source_account_id');
    }

    public function targetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'target_account_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
     public function getSourceOwnerAttribute()
    {
        return $this->sourceAccount->owner->first();
    }

    public function getTargetOwnerAttribute()
    {
        return $this->targetAccount->owner->first();
    }
    public function full_name(){
        return $this->belongsTo(User::class, 'initiated_by')->select('first_name', 'last_name');
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

    public function approval()
    {
        return $this->hasMany(Approval::class, 'entity_id');
    }

    public function deposit()
    {
        return $this->hasMany(Deposit::class);
    }

    public function withdrawal()
    {
        return $this->hasMany(WithDrawal::class);
    }

    public function transfer()
    {
        return $this->hasMany(Transfer::class);
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
        return $query->where('status', TransactionStatus::PENDING);
    }

    public function scopeByAccount($query, $accountId)
    {
        return $query->where(function ($q) use ($accountId) {
            $q->where('source_account_id', $accountId)
                ->orWhere('target_account_id', $accountId);
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
    protected static function boot()
    {
        parent::boot();

        static::retrieved(function ($transaction) {
            $transaction->loadMissing([
                'sourceAccount.user:id,first_name,last_name,email',
                'targetAccount.user:id,first_name,last_name,email',
                'initiator:id,first_name,last_name'
            ]);
        });
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
            'source_account' => $this->sourceAccount ? $this->sourceAccount->account_number : null,
            'target_account' => $this->targetAccount ? $this->targetAccount->account_number : null,
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
            'approved' => 'Approved',
            'completed' => 'Completed',
            'rejected' => 'Rejected',

            default => ucfirst($this->status)
        };
    }
}
