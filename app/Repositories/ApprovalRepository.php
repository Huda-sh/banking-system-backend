<?php

namespace App\Repositories;

use App\Models\TransactionApproval;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalLevel;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApprovalRepository
{
    /**
     * Create a new approval.
     */
    public function create(array $data): TransactionApproval
    {
        return DB::transaction(function () use ($data) {
            $approval = TransactionApproval::create($data);

            Log::info('ApprovalRepository: Approval created', [
                'approval_id' => $approval->id,
                'transaction_id' => $approval->transaction_id,
                'level' => $approval->level,
                'approver_id' => $approval->approver_id
            ]);

            return $approval;
        });
    }

    /**
     * Find an approval by ID with relations.
     */
    public function findWithRelations(int $id): ?TransactionApproval
    {
        return TransactionApproval::with(['transaction', 'approver'])->find($id);
    }

    /**
     * Get pending approvals for a user.
     */
    public function getPendingForUser(int $userId): Collection
    {
        return TransactionApproval::pending()
            ->where('approver_id', $userId)
            ->where('due_at', '>', now())
            ->with(['transaction.fromAccount', 'transaction.toAccount'])
            ->orderBy('due_at', 'asc')
            ->get();
    }

    /**
     * Get overdue approvals for a user.
     */
    public function getOverdueForUser(int $userId): Collection
    {
        return TransactionApproval::pending()
            ->where('approver_id', $userId)
            ->where('due_at', '<=', now())
            ->with(['transaction'])
            ->orderBy('due_at', 'asc')
            ->get();
    }

    /**
     * Get approval workflow for a transaction.
     */
    public function getWorkflowForTransaction(int $transactionId): Collection
    {
        return TransactionApproval::where('transaction_id', $transactionId)
            ->with(['approver'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get approval statistics.
     */
    public function getStatistics(): array
    {
        return TransactionApproval::selectRaw('
            COUNT(*) as total_approvals,
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected_count,
            SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as cancelled_count,
            AVG(TIMESTAMPDIFF(HOUR, created_at, COALESCE(approved_at, rejected_at, cancelled_at, created_at))) as avg_processing_time_hours
        ')->first()->toArray();
    }

    /**
     * Get approvals by level.
     */
    public function getByLevel(ApprovalLevel $level): Collection
    {
        return TransactionApproval::where('level', $level->value)
            ->with(['transaction', 'approver'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get recent approvals.
     */
    public function getRecent(int $limit = 50): Collection
    {
        return TransactionApproval::with(['transaction', 'approver'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get approval summary by user.
     */
    public function getSummaryByUser(int $userId): array
    {
        return TransactionApproval::where('approver_id', $userId)
            ->selectRaw('
                COUNT(*) as total_approvals,
                SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_count,
                AVG(TIMESTAMPDIFF(HOUR, created_at, COALESCE(approved_at, rejected_at, cancelled_at, created_at))) as avg_processing_time_hours
            ')->first()->toArray();
    }

    /**
     * Get escalation statistics.
     */
    public function getEscalationStatistics(): array
    {
        return TransactionApproval::whereNotNull('escalated_from_id')
            ->selectRaw('
                COUNT(*) as total_escalations,
                COUNT(DISTINCT transaction_id) as unique_transactions,
                AVG(TIMESTAMPDIFF(HOUR, created_at, escalated_at)) as avg_time_to_escalation_hours
            ')->first()->toArray();
    }
}
