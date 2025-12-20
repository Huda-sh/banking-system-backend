<?php

namespace App\Services\Transactions;

use App\Models\Transaction;
use App\Models\TransactionApproval;
use App\Models\User;
use App\Enums\TransactionStatus;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalLevel;
use App\Exceptions\ApprovalException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ApprovalWorkflowService
{
    /**
     * Default approval timeout in hours.
     */
    const DEFAULT_APPROVAL_TIMEOUT = 48;

    /**
     * Maximum number of escalation levels.
     */
    const MAX_ESCALATION_LEVELS = 3;

    /**
     * ApprovalWorkflowService constructor.
     */
    public function __construct() {}

    /**
     * Start approval workflow for a transaction.
     */
    public function startWorkflow(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            // Determine required approvers based on transaction amount and type
            $approvers = $this->determineRequiredApprovers($transaction);

            if (empty($approvers)) {
                throw new ApprovalException('No approvers determined for transaction');
            }

            // Create approval records
            $approvals = [];
            foreach ($approvers as $approverData) {
                $approval = TransactionApproval::create([
                    'transaction_id' => $transaction->id,
                    'approver_id' => $approverData['user_id'],
                    'level' => $approverData['level'],
                    'status' => ApprovalStatus::PENDING,
                    'notes' => $approverData['notes'] ?? "Auto-generated approval for {$transaction->type->value} transaction",
                    'due_at' => now()->addHours($approverData['timeout'] ?? self::DEFAULT_APPROVAL_TIMEOUT)
                ]);

                $approvals[] = $approval;
            }

            Log::info('Approval workflow started', [
                'transaction_id' => $transaction->id,
                'approval_count' => count($approvals),
                'approvers' => array_map(fn($a) => $a['user_id'], $approvers)
            ]);
        });
    }

    /**
     * Determine required approvers based on transaction details.
     */
    private function determineRequiredApprovers(Transaction $transaction): array
    {
        $amount = $transaction->amount;
        $currency = $transaction->currency;
        $transactionType = $transaction->type;
        $approvers = [];

        // Base approval levels based on amount
        if ($amount > ApprovalLevel::EXECUTIVE->getMinAmountForLevel($currency)) {
            $approvers[] = $this->getApproverForLevel(ApprovalLevel::EXECUTIVE, $transaction);
        } elseif ($amount > ApprovalLevel::SENIOR_MANAGER->getMinAmountForLevel($currency)) {
            $approvers[] = $this->getApproverForLevel(ApprovalLevel::SENIOR_MANAGER, $transaction);
        } elseif ($amount > ApprovalLevel::RISK_MANAGER->getMinAmountForLevel($currency)) {
            $approvers[] = $this->getApproverForLevel(ApprovalLevel::RISK_MANAGER, $transaction);
        } elseif ($amount > ApprovalLevel::ADMIN->getMinAmountForLevel($currency)) {
            $approvers[] = $this->getApproverForLevel(ApprovalLevel::ADMIN, $transaction);
        } elseif ($amount > ApprovalLevel::MANAGER->getMinAmountForLevel($currency)) {
            $approvers[] = $this->getApproverForLevel(ApprovalLevel::MANAGER, $transaction);
        } else {
            $approvers[] = $this->getApproverForLevel(ApprovalLevel::TELLER, $transaction);
        }

        // Additional approvals for high-risk transactions
        if ($this->isHighRiskTransaction($transaction)) {
            $approvers[] = $this->getApproverForLevel(ApprovalLevel::RISK_MANAGER, $transaction);
            $approvers[] = $this->getApproverForLevel(ApprovalLevel::COMPLIANCE_OFFICER, $transaction);
        }

        // Additional approvals for specific transaction types
        if ($transactionType === TransactionType::INTERNATIONAL_TRANSFER) {
            $approvers[] = $this->getApproverForLevel(ApprovalLevel::COMPLIANCE_OFFICER, $transaction);
        }

        return array_unique($approvers, SORT_REGULAR);
    }

    /**
     * Get approver for a specific level.
     */
    private function getApproverForLevel(ApprovalLevel $level, Transaction $transaction): array
    {
        // In production, this would use more sophisticated logic:
        // - Department-based routing
        // - Round-robin assignment
        // - Load balancing
        // - User availability

        // For now, get the first available user with the required role
        $user = $this->findFirstAvailableApprover($level, $transaction);

        if (!$user) {
            throw new ApprovalException("No available approver found for level: {$level->value}");
        }

        return [
            'user_id' => $user->id,
            'level' => $level->value,
            'notes' => "Level {$level->getLabel()} approval required for amount: {$transaction->amount} {$transaction->currency}",
            'timeout' => $this->getApprovalTimeout($level, $transaction)
        ];
    }

    /**
     * Find first available approver for a level.
     */
    private function findFirstAvailableApprover(ApprovalLevel $level, Transaction $transaction): ?User
    {
        $requiredRoles = $level->getRequiredRoles();

        return User::whereHas('roles', function ($query) use ($requiredRoles) {
            $query->whereIn('name', $requiredRoles);
        })
            ->where('is_active', true)
            ->whereDoesntHave('pendingApprovals', function ($query) {
                $query->where('status', ApprovalStatus::PENDING)
                    ->where('due_at', '>', now());
            })
            ->orderBy('last_approval_at', 'asc')
            ->first();
    }

    /**
     * Get approval timeout based on level and risk.
     */
    private function getApprovalTimeout(ApprovalLevel $level, Transaction $transaction): int
    {
        $baseTimeout = match($level) {
            ApprovalLevel::TELLER => 24,
            ApprovalLevel::MANAGER => 36,
            ApprovalLevel::ADMIN => 48,
            ApprovalLevel::COMPLIANCE_OFFICER => 72,
            ApprovalLevel::RISK_MANAGER => 72,
            ApprovalLevel::SENIOR_MANAGER => 96,
            ApprovalLevel::EXECUTIVE => 120,
        };

        // Reduce timeout for high-risk transactions
        if ($this->isHighRiskTransaction($transaction)) {
            $baseTimeout = max(12, $baseTimeout / 2);
        }

        return $baseTimeout;
    }

    /**
     * Check if transaction is high-risk.
     */
    private function isHighRiskTransaction(Transaction $transaction): bool
    {
        $riskFactors = 0;

        // Amount-based risk
        if ($transaction->amount > 100000) {
            $riskFactors++;
        }

        // New account risk
        $toAccount = $transaction->toAccount;
        if ($toAccount && $toAccount->created_at->diffInDays(now()) < 30) {
            $riskFactors++;
        }

        // International transfer risk
        if ($transaction->type === TransactionType::INTERNATIONAL_TRANSFER) {
            $riskFactors++;
        }

        // Large withdrawal risk
        if ($transaction->type === TransactionType::WITHDRAWAL && $transaction->amount > 50000) {
            $riskFactors++;
        }

        // Multiple failed attempts risk
        $recentFailedCount = Transaction::where('initiated_by', $transaction->initiated_by)
            ->where('status', TransactionStatus::FAILED)
            ->where('created_at', '>', now()->subHours(24))
            ->count();

        if ($recentFailedCount >= 3) {
            $riskFactors++;
        }

        return $riskFactors >= 2;
    }

    /**
     * Approve a transaction by a user.
     */
    public function approveTransaction(Transaction $transaction, User $approver, array $data = []): bool
    {
        return DB::transaction(function () use ($transaction, $approver, $data) {
            // Get the pending approval for this user
            $approval = $transaction->approvals()
                ->where('approver_id', $approver->id)
                ->where('status', ApprovalStatus::PENDING)
                ->lockForUpdate()
                ->first();

            if (!$approval) {
                throw new ApprovalException('No pending approval found for this user');
            }

            if (!$this->canApprove($approval, $transaction)) {
                throw new ApprovalException('Transaction cannot be approved in current state');
            }

            // Update approval record
            $approval->update([
                'status' => ApprovalStatus::APPROVED,
                'notes' => $data['notes'] ?? $approval->notes,
                'approved_at' => now(),
                'approved_ip' => request()->ip() ?? 'system'
            ]);

            // Check if all approvals are complete
            $allApproved = !TransactionApproval::where('transaction_id', $transaction->id)
                ->where('status', ApprovalStatus::PENDING)
                ->exists();

            if ($allApproved) {
                return $this->completeTransactionApproval($transaction, $approver);
            }

            return true;
        });
    }

    /**
     * Check if approval can proceed.
     */
    private function canApprove(TransactionApproval $approval, Transaction $transaction): bool
    {
        // Check if transaction is still in approval state
        if ($transaction->status !== TransactionStatus::PENDING) {
            return false;
        }

        // Check if approval is still pending
        if ($approval->status !== ApprovalStatus::PENDING) {
            return false;
        }

        // Check if approval has timed out
        if ($approval->due_at && $approval->due_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Complete transaction approval when all approvals are done.
     */
    private function completeTransactionApproval(Transaction $transaction, User $finalApprover): bool
    {
        // Update transaction status
        $transaction->update([
            'status' => TransactionStatus::APPROVED,
            'approved_by' => $finalApprover->id,
            'approved_at' => now()
        ]);

        // Queue for processing
        // In production, this would dispatch a job
        Log::info('Transaction approved and ready for processing', [
            'transaction_id' => $transaction->id,
            'approved_by' => $finalApprover->id
        ]);

        return true;
    }

    /**
     * Reject a transaction by a user.
     */
    public function rejectTransaction(Transaction $transaction, User $approver, array $data = []): bool
    {
        return DB::transaction(function () use ($transaction, $approver, $data) {
            // Get the pending approval for this user
            $approval = $transaction->approvals()
                ->where('approver_id', $approver->id)
                ->where('status', ApprovalStatus::PENDING)
                ->lockForUpdate()
                ->first();

            if (!$approval) {
                throw new ApprovalException('No pending approval found for this user');
            }

            // Update approval record
            $approval->update([
                'status' => ApprovalStatus::REJECTED,
                'notes' => $data['notes'] ?? $approval->notes,
                'rejected_at' => now(),
                'rejected_ip' => request()->ip() ?? 'system'
            ]);

            // Update transaction status
            $transaction->update([
                'status' => TransactionStatus::REJECTED,
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'rejected_by' => $approver->id,
                    'rejected_at' => now()->format('Y-m-d H:i:s'),
                    'rejection_notes' => $data['notes'] ?? null,
                    'rejected_approval_id' => $approval->id
                ])
            ]);

            // Cancel other pending approvals
            TransactionApproval::where('transaction_id', $transaction->id)
                ->where('status', ApprovalStatus::PENDING)
                ->where('id', '!=', $approval->id)
                ->update([
                    'status' => ApprovalStatus::CANCELLED,
                    'notes' => 'Cancelled due to rejection by another approver'
                ]);

            Log::warning('Transaction rejected', [
                'transaction_id' => $transaction->id,
                'rejected_by' => $approver->id,
                'notes' => $data['notes'] ?? null
            ]);

            return true;
        });
    }

    /**
     * Escalate an approval to a higher level.
     */
    public function escalateApproval(TransactionApproval $approval, User $escalator, array $data = []): TransactionApproval
    {
        return DB::transaction(function () use ($approval, $escalator, $data) {
            if ($approval->status !== ApprovalStatus::PENDING) {
                throw new ApprovalException('Only pending approvals can be escalated');
            }

            // Cancel current approval
            $approval->update([
                'status' => ApprovalStatus::ESCALATED,
                'notes' => $data['notes'] ?? $approval->notes,
                'escalated_at' => now(),
                'escalated_by' => $escalator->id
            ]);

            // Get next escalation level
            $currentLevel = ApprovalLevel::fromValue($approval->level);
            $escalationPath = $currentLevel->getEscalationPath();

            if (empty($escalationPath)) {
                throw new ApprovalException('No escalation path available for this level');
            }

            $nextLevel = $escalationPath[0];
            $nextApprover = $this->findFirstAvailableApprover($nextLevel, $approval->transaction);

            if (!$nextApprover) {
                throw new ApprovalException("No available approver found for escalation level: {$nextLevel->value}");
            }

            // Create new approval at higher level
            return TransactionApproval::create([
                'transaction_id' => $approval->transaction_id,
                'approver_id' => $nextApprover->id,
                'level' => $nextLevel->value,
                'status' => ApprovalStatus::PENDING,
                'notes' => "Escalated from {$currentLevel->getLabel()} by {$escalator->name}: " . ($data['notes'] ?? 'No reason provided'),
                'due_at' => now()->addHours($this->getApprovalTimeout($nextLevel, $approval->transaction)),
                'escalated_from_id' => $approval->id
            ]);
        });
    }

    /**
     * Cancel an approval workflow.
     */
    public function cancelWorkflow(Transaction $transaction, User $cancelledBy, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($transaction, $cancelledBy, $reason) {
            if (!$transaction->requiresApproval()) {
                throw new ApprovalException('Transaction does not have an active approval workflow');
            }

            // Cancel all pending approvals
            TransactionApproval::where('transaction_id', $transaction->id)
                ->where('status', ApprovalStatus::PENDING)
                ->update([
                    'status' => ApprovalStatus::CANCELLED,
                    'notes' => $reason ?? 'Workflow cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by' => $cancelledBy->id
                ]);

            // Update transaction status
            $transaction->update([
                'status' => TransactionStatus::CANCELLED,
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'workflow_cancelled_by' => $cancelledBy->id,
                    'workflow_cancelled_at' => now()->format('Y-m-d H:i:s'),
                    'workflow_cancellation_reason' => $reason
                ])
            ]);

            Log::info('Approval workflow cancelled', [
                'transaction_id' => $transaction->id,
                'cancelled_by' => $cancelledBy->id,
                'reason' => $reason
            ]);

            return true;
        });
    }

    /**
     * Get pending approvals for a user.
     */
    public function getUserPendingApprovals(User $user, int $limit = 20): array
    {
        $approvals = TransactionApproval::where('approver_id', $user->id)
            ->where('status', ApprovalStatus::PENDING)
            ->where('due_at', '>', now())
            ->with(['transaction', 'transaction.fromAccount', 'transaction.toAccount'])
            ->orderBy('due_at', 'asc')
            ->limit($limit)
            ->get();

        return [
            'pending_count' => $approvals->count(),
            'approvals' => $approvals->map(function ($approval) {
                return $this->formatApprovalForDisplay($approval);
            }),
            'overdue_count' => TransactionApproval::where('approver_id', $user->id)
                ->where('status', ApprovalStatus::PENDING)
                ->where('due_at', '<=', now())
                ->count()
        ];
    }

    /**
     * Format approval for display.
     */
    private function formatApprovalForDisplay(TransactionApproval $approval): array
    {
        $transaction = $approval->transaction;
        $timeRemaining = now()->diffInHours($approval->due_at, false);
        $isOverdue = $timeRemaining < 0;

        return [
            'id' => $approval->id,
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'type' => $transaction->type->getLabel(),
            'from_account' => $transaction->fromAccount ? $transaction->fromAccount->account_number : null,
            'to_account' => $transaction->toAccount ? $transaction->toAccount->account_number : null,
            'level' => $approval->level,
            'level_label' => ApprovalLevel::fromValue($approval->level)?->getLabel() ?? $approval->level,
            'due_at' => $approval->due_at->format('Y-m-d H:i:s'),
            'time_remaining' => $isOverdue ? 'Overdue' : "{$timeRemaining} hours remaining",
            'is_overdue' => $isOverdue,
            'notes' => $approval->notes,
            'created_at' => $approval->created_at->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Get approval workflow summary for a transaction.
     */
    public function getWorkflowSummary(Transaction $transaction): array
    {
        $approvals = $transaction->approvals()->with('approver')->get();

        $summary = [
            'transaction_id' => $transaction->id,
            'total_approvals' => $approvals->count(),
            'pending_count' => $approvals->where('status', ApprovalStatus::PENDING)->count(),
            'approved_count' => $approvals->where('status', ApprovalStatus::APPROVED)->count(),
            'rejected_count' => $approvals->where('status', ApprovalStatus::REJECTED)->count(),
            'escalated_count' => $approvals->where('status', ApprovalStatus::ESCALATED)->count(),
            'cancelled_count' => $approvals->where('status', ApprovalStatus::CANCELLED)->count(),
            'approval_percent' => $approvals->count() > 0
                ? round(($approvals->where('status', ApprovalStatus::APPROVED)->count() / $approvals->count()) * 100, 2)
                : 0,
            'status' => $transaction->status->getLabel(),
            'can_be_approved' => $transaction->requiresApproval() && $transaction->getNextPendingApproval()?->approver_id === auth()->id(),
            'approvals' => $approvals->map(function ($approval) {
                return [
                    'id' => $approval->id,
                    'approver' => $approval->approver ? $approval->approver->name : 'Unknown',
                    'level' => $approval->level,
                    'level_label' => ApprovalLevel::fromValue($approval->level)?->getLabel() ?? $approval->level,
                    'status' => $approval->status->getLabel(),
                    'status_color' => $approval->status->getColor(),
                    'due_at' => $approval->due_at ? $approval->due_at->format('Y-m-d H:i:s') : null,
                    'approved_at' => $approval->approved_at ? $approval->approved_at->format('Y-m-d H:i:s') : null,
                    'rejected_at' => $approval->rejected_at ? $approval->rejected_at->format('Y-m-d H:i:s') : null,
                    'notes' => $approval->notes
                ];
            })
        ];

        return $summary;
    }

    /**
     * Process overdue approvals.
     */
    public function processOverdueApprovals(): array
    {
        $results = [
            'processed' => 0,
            'escalated' => 0,
            'cancelled' => 0
        ];

        $overdueApprovals = TransactionApproval::where('status', ApprovalStatus::PENDING)
            ->where('due_at', '<=', now())
            ->with('transaction')
            ->limit(100)
            ->get();

        foreach ($overdueApprovals as $approval) {
            try {
                $this->handleOverdueApproval($approval);
                $results['processed']++;

            } catch (\Exception $e) {
                Log::error('Failed to process overdue approval', [
                    'approval_id' => $approval->id,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e)
                ]);
            }
        }

        return $results;
    }

    /**
     * Handle a single overdue approval.
     */
    private function handleOverdueApproval(TransactionApproval $approval): void
    {
        $transaction = $approval->transaction;
        $currentLevel = ApprovalLevel::fromValue($approval->level);
        $escalationPath = $currentLevel->getEscalationPath();

        if (!empty($escalationPath)) {
            // Escalate to next level
            $nextLevel = $escalationPath[0];
            $nextApprover = $this->findFirstAvailableApprover($nextLevel, $transaction);

            if ($nextApprover) {
                $this->escalateApproval($approval, $transaction->initiatedBy, [
                    'notes' => 'Auto-escalated due to approval timeout'
                ]);
                Log::info('Approval auto-escalated', [
                    'approval_id' => $approval->id,
                    'new_approver' => $nextApprover->id,
                    'level' => $nextLevel->value
                ]);
            } else {
                $this->cancelApprovalDueToNoApprovers($approval);
            }
        } else {
            // Cancel the workflow if no escalation path
            $this->cancelWorkflow($transaction, $transaction->initiatedBy, 'Auto-cancelled due to approval timeout');
            Log::warning('Approval workflow auto-cancelled', [
                'transaction_id' => $transaction->id,
                'approval_id' => $approval->id
            ]);
        }
    }

    /**
     * Cancel approval due to no available approvers.
     */
    private function cancelApprovalDueToNoApprovers(TransactionApproval $approval): void
    {
        $transaction = $approval->transaction;

        TransactionApproval::where('transaction_id', $transaction->id)
            ->where('status', ApprovalStatus::PENDING)
            ->update([
                'status' => ApprovalStatus::CANCELLED,
                'notes' => 'Cancelled due to no available approvers'
            ]);

        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'failure_reason' => 'No available approvers for workflow',
                'cancelled_at' => now()->format('Y-m-d H:i:s')
            ])
        ]);

        Log::error('Approval cancelled due to no available approvers', [
            'transaction_id' => $transaction->id,
            'approval_id' => $approval->id
        ]);
    }
}
