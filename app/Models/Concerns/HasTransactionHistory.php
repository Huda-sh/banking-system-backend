<?php


namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Transaction;

trait HasTransactionHistory
{
    /**
     * Get all transactions associated with the model.
     */
    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactable');
    }

    /**
     * Get transaction history with pagination.
     */
    public function getTransactionHistory(int $perPage = 25, array $filters = [])
    {
        $query = $this->transactions()->with(['fromAccount', 'toAccount', 'initiatedBy']);

        // Apply filters
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $query->whereBetween('created_at', [$filters['start_date'], $filters['end_date']]);
        }

        if (isset($filters['min_amount'])) {
            $query->where('amount', '>=', $filters['min_amount']);
        }

        if (isset($filters['max_amount'])) {
            $query->where('amount', '<=', $filters['max_amount']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get transaction summary statistics.
     */
    public function getTransactionSummary(array $dateRange = null): array
    {
        $query = $this->transactions()->where('status', 'completed');

        if ($dateRange) {
            $query->whereBetween('created_at', $dateRange);
        }

        $summary = $query->selectRaw('
            COUNT(*) as total_transactions,
            SUM(amount) as total_amount,
            AVG(amount) as average_amount,
            MIN(amount) as min_amount,
            MAX(amount) as max_amount,
            SUM(fee) as total_fees
        ')->first();

        // Get transaction type breakdown
        $typeBreakdown = $query->selectRaw('type, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('type')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->type => [
                    'count' => $item->count,
                    'total' => $item->total
                ]];
            })
            ->toArray();

        return [
            'total_transactions' => $summary->total_transactions ?? 0,
            'total_amount' => $summary->total_amount ?? 0,
            'average_amount' => $summary->average_amount ?? 0,
            'min_amount' => $summary->min_amount ?? 0,
            'max_amount' => $summary->max_amount ?? 0,
            'total_fees' => $summary->total_fees ?? 0,
            'type_breakdown' => $typeBreakdown
        ];
    }

    /**
     * Get recent transactions (last 10).
     */
    public function getRecentTransactions(int $limit = 10)
    {
        return $this->transactions()
            ->with(['fromAccount', 'toAccount', 'initiatedBy'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Check if there are any pending transactions.
     */
    public function hasPendingTransactions(): bool
    {
        return $this->transactions()
            ->whereIn('status', ['pending', 'pending_approval'])
            ->exists();
    }

    /**
     * Get transaction count by status.
     */
    public function getTransactionCountByStatus(): array
    {
        return $this->transactions()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->status => $item->count];
            })
            ->toArray();
    }

    /**
     * Get monthly transaction trends.
     */
    public function getMonthlyTransactionTrends(int $months = 12): array
    {
        $startDate = now()->subMonths($months)->startOfMonth();

        return $this->transactions()
            ->where('status', 'completed')
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                DATE_FORMAT(created_at, "%Y-%m") as month,
                COUNT(*) as transaction_count,
                SUM(amount) as total_amount,
                SUM(fee) as total_fees
            ')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(function ($item) {
                return [
                    'month' => $item->month,
                    'transaction_count' => $item->transaction_count,
                    'total_amount' => $item->total_amount,
                    'total_fees' => $item->total_fees,
                    'average_transaction' => $item->transaction_count > 0 ? $item->total_amount / $item->transaction_count : 0
                ];
            })
            ->toArray();
    }
}
