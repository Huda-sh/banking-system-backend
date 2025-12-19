<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\TransactionApproval;
use App\Enums\ApprovalStatus;
use App\Enums\TransactionStatus;
use App\Exceptions\ApprovalException;

trait HasApprovalWorkflow
{
    /**
     * Get all approvals associated with the model.
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(TransactionApproval::class, 'approvable');
    }

    /**
     * Get pending approvals for the model.
     */
    public function pendingApprovals()
    {
        return $this->approvals()->where('status', ApprovalStatus::PENDING);
    }

    /**
     * Get completed approvals for the model.
     */
    public function completedApprovals()
    {
        return $this->approvals()->whereIn('status', [ApprovalStatus::APPROVED, ApprovalStatus::REJECTED]);
    }

    /**
     * Check if the model requires approval.
     */
    public function requiresApproval(): bool
    {
        return $this->approvals()->exists() &&
            $this->approvals()->where('status', ApprovalStatus::PENDING)->exists();
    }

    /**
     * Check if all approvals are completed.
     */
    public function allApprovalsCompleted(): bool
    {
        return $this->approvals()->exists() &&
            !$this->approvals()->where('status', ApprovalStatus::PENDING)->exists();
    }

    /**
     * Check if all approvals are approved.
     */
    public function allApprovalsApproved(): bool
    {
        return $this->allApprovalsCompleted() &&
            !$this->approvals()->where('status', ApprovalStatus::REJECTED)->exists();
    }

    /**
     * Get approval status summary.
     */
    public function getApprovalStatusSummary(): array
    {
        $total = $this->approvals()->count();
        $pending = $this->approvals()->where('status', ApprovalStatus::PENDING)->count();
        $approved = $this->approvals()->where('status', ApprovalStatus::APPROVED)->count();
        $rejected = $this->approvals()->where('status', ApprovalStatus::REJECTED)->count();

        return [
            'total' => $total,
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'completed' => $approved + $rejected,
            'percentage_complete' => $total > 0 ? round(($approved + $rejected) / $total * 100, 2) : 0,
            'status' => match(true) {
                $rejected > 0 => 'rejected',
                $pending > 0 => 'pending',
                $approved === $total => 'approved',
                default => 'unknown'
            }
        ];
    }

    /**
     * Get next pending approval.
     */
    public function getNextPendingApproval()
    {
        return $this->approvals()
            ->where('status', ApprovalStatus::PENDING)
            ->orderBy('created_at', 'asc')
            ->first();
    }

    /**
     * Get approval history.
     */
    public function getApprovalHistory()
    {
        return $this->approvals()
            ->with(['approver'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($approval) {
                return $approval->getApprovalDetails();
            });
    }

    /**
     * Check if user can approve this model.
     */
    public function canUserApprove($user): bool
    {
        $pendingApproval = $this->getNextPendingApproval();

        return $pendingApproval &&
            $pendingApproval->approver_id === $user->id &&
            $pendingApproval->isPending();
    }

    /**
     * Approve the model by user.
     */
    public function approveByUser($user, array $data = []): bool
    {
        $approval = $this->getNextPendingApproval();

        if (!$approval || $approval->approver_id !== $user->id) {
            throw new ApprovalException("User is not authorized to approve this transaction");
        }

        return $approval->approve($data);
    }

    /**
     * Reject the model by user.
     */
    public function rejectByUser($user, array $data = []): bool
    {
        $approval = $this->getNextPendingApproval();

        if (!$approval || $approval->approver_id !== $user->id) {
            throw new ApprovalException("User is not authorized to reject this transaction");
        }

        return $approval->reject($data);
    }

    /**
     * Cancel all pending approvals.
     */
    public function cancelPendingApprovals(): int
    {
        return $this->approvals()
            ->where('status', ApprovalStatus::PENDING)
            ->update([
                'status' => ApprovalStatus::CANCELLED,
                'notes' => 'Approval cancelled due to transaction cancellation',
                'cancelled_at' => now()
            ]);
    }

    /**
     * Get approval workflow details.
     */
    public function getApprovalWorkflowDetails(): array
    {
        $summary = $this->getApprovalStatusSummary();

        return [
            'workflow_id' => $this->id,
            'workflow_type' => class_basename($this),
            'status_summary' => $summary,
            'approvals' => $this->getApprovalHistory()->toArray(),
            'next_approver' => $this->getNextPendingApproval() ? $this->getNextPendingApproval()->approver->full_name : null,
            'can_be_approved' => $this->requiresApproval() && $this->getNextPendingApproval()?->approver_id === auth()->id(),
            'all_completed' => $this->allApprovalsCompleted(),
            'all_approved' => $this->allApprovalsApproved()
        ];
    }

    /**
     * Create approval workflow.
     */
    public function createApprovalWorkflow(array $approvers): void
    {
        foreach ($approvers as $approverData) {
            $this->approvals()->create([
                'approver_id' => $approverData['user_id'],
                'level' => $approverData['level'],
                'status' => ApprovalStatus::PENDING,
                'notes' => $approverData['notes'] ?? "Auto-generated approval"
            ]);
        }
    }

    /**
     * Handle transaction completion after approval.
     */
    public function processApprovedTransaction()
    {
        if (!$this instanceof \App\Models\Transaction) {
            return;
        }

        if ($this->allApprovalsApproved() && $this->status === TransactionStatus::APPROVED) {
            // Dispatch job to process the transaction
            \App\Jobs\ProcessApprovedTransaction::dispatch($this);
        }
    }
}
