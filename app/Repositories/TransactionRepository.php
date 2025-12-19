<?php

namespace App\Repositories;

use App\Models\Transaction;
use App\Models\Account;
use App\Models\User;
use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionRepository
{
    /**
     * Create a new transaction.
     */
    public function create(array $data): Transaction
    {
        return DB::transaction(function () use ($data) {
            $transaction = Transaction::create($data);

            Log::info('TransactionRepository: Transaction created', [
                'transaction_id' => $transaction->id,
                'type' => $transaction->type->value,
                'amount' => $transaction->amount
            ]);

            return $transaction;
        });
    }

    /**
     * Find a transaction by ID with relationships.
     */
    public function findWithRelations(int $id): ?Transaction
    {
        return Transaction::with([
            'fromAccount',
            'toAccount',
            'initiatedBy',
            'processedBy',
            'approvedBy',
            'approvals',
            'auditLogs',
            'scheduledTransaction'
        ])->find($id);
    }

    /**
     * Get transactions for a specific account.
     */
    public function getByAccount(int $accountId, array $filters = []): Collection
    {
        return $this->applyFilters(
            Transaction::byAccount($accountId),
            $filters
        )->orderBy('created_at', 'desc')->get();
    }

    /**
     * Get transactions for a specific user.
     */
    public function getByUser(int $userId, array $filters = []): Collection
    {
        return $this->applyFilters(
            Transaction::where('initiated_by', $userId),
            $filters
        )->orderBy('created_at', 'desc')->get();
    }

    /**
     * Get transaction summary statistics.
     */
    public function getSummary(array $filters = []): array
    {
        $query = $this->applyFilters(Transaction::query(), $filters);

        return $query->selectRaw('
            COUNT(*) as total_transactions,
            SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN status = "pending_approval" THEN 1 ELSE 0 END) as pending_approval_count,
            SUM(amount) as total_amount,
            AVG(amount) as average_amount,
            SUM(fee) as total_fees
        ')->first()->toArray();
    }

    /**
     * Get recent transactions.
     */
    public function getRecent(int $limit = 20, array $filters = []): Collection
    {
        return $this->applyFilters(Transaction::query(), $filters)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get transactions requiring approval.
     */
    public function getPendingApprovals(array $filters = []): Collection
    {
        return $this->applyFilters(
            Transaction::pendingApproval(),
            $filters
        )->with(['fromAccount', 'toAccount', 'initiatedBy'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get monthly transaction trends.
     */
    public function getMonthlyTrends(Carbon $startDate, Carbon $endDate): Collection
    {
        return Transaction::completed()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                DATE_FORMAT(created_at, "%Y-%m") as month,
                COUNT(*) as transaction_count,
                SUM(amount) as total_amount,
                AVG(amount) as average_amount,
                SUM(fee) as total_fees
            ')
            ->groupBy('month')
            ->orderBy('month', 'asc')
            ->get();
    }

    /**
     * Apply filters to query.
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_range'])) {
            $range = $filters['date_range'];
            $query->whereBetween('created_at', [$range['start'], $range['end']]);
        }

        if (isset($filters['min_amount'])) {
            $query->where('amount', '>=', $filters['min_amount']);
        }

        if (isset($filters['max_amount'])) {
            $query->where('amount', '<=', $filters['max_amount']);
        }

        if (isset($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        if (isset($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', $search)
                    ->orWhereHas('fromAccount', fn($q) => $q->where('account_number', 'like', $search))
                    ->orWhereHas('toAccount', fn($q) => $q->where('account_number', 'like', $search));
            });
        }

        return $query;
    }

    /**
     * Get transaction count by status.
     */
    public function getCountByStatus(): array
    {
        return Transaction::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn($item) => [$item->status => $item->count])
            ->toArray();
    }

    /**
     * Get high-risk transactions.
     */
    public function getHighRiskTransactions(array $filters = []): Collection
    {
        return $this->applyFilters(Transaction::query(), $filters)
            ->where('metadata->risk_score', '>=', 70)
            ->orWhere('amount', '>=', 100000)
            ->orderBy('metadata->risk_score', 'desc')
            ->orderBy('amount', 'desc')
            ->get();
    }
}
