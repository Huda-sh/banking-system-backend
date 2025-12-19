<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalLevel;
use App\Exceptions\ApprovalException;
use App\Models\Concerns\HasApprovalWorkflow;

class TransactionApproval extends Model
{
    use SoftDeletes, HasFactory, HasApprovalWorkflow;

    protected $fillable = [
        'transaction_id', 'approver_id', 'level', 'status', 'notes'
    ];

    protected $casts = [
        'status' => ApprovalStatus::class,
        'level' => ApprovalLevel::class
    ];

    // Approval limits by level
    const APPROVAL_LIMITS = [
        'teller' => 5000.00,
        'manager' => 25000.00,
        'admin' => 100000.00,
        'risk_manager' => 500000.00
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($approval) {
            $approval->validateApproval();
        });
    }

    /**
     * Validate approval data before saving.
     */
    public function validateApproval(): void
    {
        // Validate approval level
        if (!array_key_exists($this->level->value, self::APPROVAL_LIMITS)) {
            throw new ApprovalException("Invalid approval level: " . $this->level->value);
        }

        // Validate transaction exists
        $transaction = Transaction::find($this->transaction_id);
        if (!$transaction) {
            throw new ApprovalException("Transaction not found");
        }

        // Validate approver exists and has the right role
        $approver = User::find($this->approver_id);
        if (!$approver || !$approver->hasRole($this->level->value)) {
            throw new ApprovalException("Approver does not have the required role: " . $this->level->value);
        }

        // Validate amount against approval limits
        if ($transaction->amount > self::APPROVAL_LIMITS[$this->level->value]) {
            throw new ApprovalException(
                "Approver level '{$this->level->value}' cannot approve amounts over " .
                self::APPROVAL_LIMITS[$this->level->value] . " " . $transaction->currency
            );
        }
    }

    /**
     * Relationships
     */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', ApprovalStatus::PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', ApprovalStatus::APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', ApprovalStatus::REJECTED);
    }

    public function scopeByLevel($query, $level)
    {
        return $query->where('level', $level);
    }

    public function scopeByTransaction($query, $transactionId)
    {
        return $query->where('transaction_id', $transactionId);
    }

    /**
     * Business logic methods
     */
    public function approve(array $data = []): bool
    {
        if ($this->status !== ApprovalStatus::PENDING) {
            throw new ApprovalException("Only pending approvals can be approved");
        }

        $this->update([
            'status' => ApprovalStatus::APPROVED,
            'notes' => $data['notes'] ?? $this->notes,
            'approved_at' => now()
        ]);

        // Check if all approvals are complete
        $this->checkTransactionCompletion();

        return true;
    }

    public function reject(array $data = []): bool
    {
        if ($this->status !== ApprovalStatus::PENDING) {
            throw new ApprovalException("Only pending approvals can be rejected");
        }

        $this->update([
            'status' => ApprovalStatus::REJECTED,
            'notes' => $data['notes'] ?? $this->notes,
            'rejected_at' => now()
        ]);

        // Update transaction status
        $this->transaction->update([
            'status' => TransactionStatus::FAILED,
            'metadata' => array_merge($this->transaction->metadata ?? [], [
                'rejected_by' => $this->approver_id,
                'rejection_notes' => $data['notes'] ?? null,
                'rejected_at' => now()->format('Y-m-d H:i:s')
            ])
        ]);

        return true;
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === ApprovalStatus::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === ApprovalStatus::REJECTED;
    }

    private function checkTransactionCompletion(): void
    {
        $transaction = $this->transaction;

        // Check if all approvals for this transaction are approved
        $allApproved = !TransactionApproval::where('transaction_id', $transaction->id)
            ->where('status', ApprovalStatus::PENDING)
            ->exists();

        if ($allApproved) {
            // All approvals completed, update transaction status
            $transaction->update([
                'status' => TransactionStatus::APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now()
            ]);

            // Queue the transaction for processing
            $transaction->processApprovedTransaction();
        }
    }

    /**
     * Get approval details for API/Display
     */
    public function getApprovalDetails(): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'approver' => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->full_name,
                'email' => $this->approver->email,
                'role' => $this->approver->roles->pluck('name')->implode(', ')
            ] : null,
            'level' => $this->level->value,
            'level_label' => $this->level->getLabel(),
            'status' => $this->status->value,
            'status_label' => $this->status->getLabel(),
            'notes' => $this->notes,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            'approved_at' => $this->approved_at ? $this->approved_at->format('Y-m-d H:i:s') : null,
            'rejected_at' => $this->rejected_at ? $this->rejected_at->format('Y-m-d H:i:s') : null
        ];
    }

    /**
     * Create approval workflow for transaction
     */
    public static function createWorkflowForTransaction(Transaction $transaction): array
    {
        $approvers = self::determineApprovers($transaction);
        $approvals = [];

        foreach ($approvers as $approverData) {
            $approval = self::create([
                'transaction_id' => $transaction->id,
                'approver_id' => $approverData['user_id'],
                'level' => $approverData['level'],
                'status' => ApprovalStatus::PENDING,
                'notes' => "Auto-generated approval for {$transaction->type->value} transaction"
            ]);

            $approvals[] = $approval;
        }

        return $approvals;
    }

    private static function determineApprovers(Transaction $transaction): array
    {
        $amount = $transaction->amount;
        $approvers = [];

        // Determine required approval levels based on amount
        if ($amount > self::APPROVAL_LIMITS['admin']) {
            $approvers[] = ['level' => ApprovalLevel::RISK_MANAGER, 'user_id' => self::getApproverForLevel(ApprovalLevel::RISK_MANAGER)];
        }

        if ($amount > self::APPROVAL_LIMITS['manager']) {
            $approvers[] = ['level' => ApprovalLevel::ADMIN, 'user_id' => self::getApproverForLevel(ApprovalLevel::ADMIN)];
        }

        if ($amount > self::APPROVAL_LIMITS['teller']) {
            $approvers[] = ['level' => ApprovalLevel::MANAGER, 'user_id' => self::getApproverForLevel(ApprovalLevel::MANAGER)];
        }

        // Always add at least one approver for amounts over teller limit
        if (empty($approvers) && $amount > self::APPROVAL_LIMITS['teller']) {
            $approvers[] = ['level' => ApprovalLevel::TELLER, 'user_id' => self::getApproverForLevel(ApprovalLevel::TELLER)];
        }

        return $approvers;
    }

    private static function getApproverForLevel(ApprovalLevel $level): int
    {
        // In production, this would get the appropriate approver based on:
        // - department hierarchy
        // - round-robin assignment
        // - specific business rules

        // For now, get the first user with the required role
        return User::role($level->value)->firstOrFail()->id;
    }
}
