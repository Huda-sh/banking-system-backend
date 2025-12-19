<?php

namespace App\Repositories;

use App\Models\ScheduledTransaction;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScheduledTransactionRepository
{
    /**
     * Create a new scheduled transaction.
     */
    public function create(array $data): ScheduledTransaction
    {
        return DB::transaction(function () use ($data) {
            $scheduled = ScheduledTransaction::create($data);

            Log::info('ScheduledTransactionRepository: Scheduled transaction created', [
                'scheduled_id' => $scheduled->id,
                'frequency' => $scheduled->frequency,
                'next_execution' => $scheduled->next_execution?->format('Y-m-d H:i:s')
            ]);

            return $scheduled;
        });
    }

    /**
     * Find a scheduled transaction by ID with relations.
     */
    public function findWithRelations(int $id): ?ScheduledTransaction
    {
        return ScheduledTransaction::with(['transaction'])->find($id);
    }

    /**
     * Get all due scheduled transactions.
     */
    public function getDueTransactions(): Collection
    {
        return ScheduledTransaction::due()
            ->with('transaction')
            ->orderBy('next_execution', 'asc')
            ->get();
    }

    /**
     * Get active scheduled transactions for a user.
     */
    public function getActiveByUser(int $userId): Collection
    {
        return ScheduledTransaction::active()
            ->whereHas('transaction', function ($query) use ($userId) {
                $query->where('initiated_by', $userId);
            })
            ->with(['transaction.fromAccount', 'transaction.toAccount'])
            ->orderBy('next_execution', 'asc')
            ->get();
    }

    /**
     * Get scheduled transaction history.
     */
    public function getHistoryBySchedule(int $scheduledId): Collection
    {
        return Transaction::where('metadata->scheduled_transaction_id', $scheduledId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get statistics for scheduled transactions.
     */
    public function getStatistics(): array
    {
        return ScheduledTransaction::selectRaw('
            COUNT(*) as total_scheduled,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_count,
            AVG(execution_count) as avg_executions,
            MAX(execution_count) as max_executions
        ')->first()->toArray();
    }

    /**
     * Get upcoming scheduled transactions.
     */
    public function getUpcoming(Carbon $date, int $limit = 50): Collection
    {
        return ScheduledTransaction::active()
            ->where('next_execution', '<=', $date->endOfDay())
            ->with('transaction')
            ->orderBy('next_execution', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get scheduled transactions by frequency.
     */
    public function getByFrequency(string $frequency): Collection
    {
        return ScheduledTransaction::where('frequency', $frequency)
            ->with('transaction')
            ->get();
    }

    /**
     * Get failed scheduled transactions.
     */
    public function getFailed(): Collection
    {
        return ScheduledTransaction::whereHas('transaction', function ($query) {
            $query->where('status', 'failed')
                ->where('metadata->failure_count', '>=', 3);
        })->with('transaction')
            ->get();
    }

    /**
     * Get scheduled transactions count by status.
     */
    public function getCountByStatus(): array
    {
        return ScheduledTransaction::selectRaw('
            is_active,
            COUNT(*) as count
        ')->groupBy('is_active')
            ->get()
            ->mapWithKeys(fn($item) => [$item->is_active ? 'active' : 'inactive' => $item->count])
            ->toArray();
    }
}
