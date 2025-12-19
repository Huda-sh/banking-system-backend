<?php

namespace App\Patterns\Observer\Observers;

use App\Models\Transaction;
use App\Models\Account;
use App\Models\User;
use App\Patterns\Observer\Interfaces\TransactionObserver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Exceptions\ObserverException;
use Carbon\Carbon;

class BalanceUpdateObserver implements TransactionObserver
{
    private int $priority = 30;
    private bool $enabled = true;
    private array $cacheSettings;

    public function __construct()
    {
        $this->enabled = config('banking.balance_updates.enabled', true);
        $this->cacheSettings = config('banking.cache', [
            'balance_ttl' => 300, // 5 minutes
            'daily_summary_ttl' => 86400, // 24 hours
            'monthly_summary_ttl' => 2592000 // 30 days
        ]);
    }

    public function onTransactionCreated(Transaction $transaction): void
    {
        // No balance updates needed on creation
    }

    public function onTransactionCompleted(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            Log::debug('BalanceUpdateObserver: Updating balances for completed transaction', [
                'transaction_id' => $transaction->id,
                'from_account' => $transaction->from_account_id,
                'to_account' => $transaction->to_account_id
            ]);

            $this->updateAccountBalances($transaction);
            $this->updateUserBalances($transaction);
            $this->updateCacheBalances($transaction);
            $this->updateDailySummaries($transaction);

        } catch (\Exception $e) {
            Log::error('BalanceUpdateObserver: Failed to update balances', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Balance update failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onTransactionFailed(Transaction $transaction): void
    {
        // No balance updates needed for failed transactions
    }

    public function onTransactionApproved(Transaction $transaction): void
    {
        // Balance updates happen on completion, not approval
    }

    public function onTransactionReversed(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            Log::debug('BalanceUpdateObserver: Reversing balances for reversed transaction', [
                'transaction_id' => $transaction->id,
                'original_transaction_id' => $transaction->metadata['original_transaction_id'] ?? null
            ]);

            // For reversals, we need to reverse the balance updates
            $this->updateAccountBalances($transaction, true); // true for reversal
            $this->updateUserBalances($transaction, true);
            $this->clearCacheBalances($transaction);
            $this->updateDailySummaries($transaction, true);

        } catch (\Exception $e) {
            Log::error('BalanceUpdateObserver: Failed to reverse balances', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Balance reversal failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function onScheduledTransactionExecuted(Transaction $transaction): void
    {
        if (!$this->isEnabled()) return;

        try {
            Log::debug('BalanceUpdateObserver: Updating balances for scheduled transaction', [
                'transaction_id' => $transaction->id,
                'scheduled_id' => $transaction->scheduledTransaction->id ?? null
            ]);

            $this->updateAccountBalances($transaction);
            $this->updateUserBalances($transaction);
            $this->updateCacheBalances($transaction);
            $this->updateDailySummaries($transaction);

        } catch (\Exception $e) {
            Log::error('BalanceUpdateObserver: Failed to update balances for scheduled transaction', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ObserverException("Balance update failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function updateAccountBalances(Transaction $transaction, bool $isReversal = false): void
    {
        if ($transaction->from_account_id) {
            $this->updateAccountBalance($transaction->from_account_id, $transaction, $isReversal, 'from');
        }

        if ($transaction->to_account_id) {
            $this->updateAccountBalance($transaction->to_account_id, $transaction, $isReversal, 'to');
        }

        // Update parent account balances if applicable
        $this->updateParentAccountBalances($transaction, $isReversal);
    }

    private function updateAccountBalance(int $accountId, Transaction $transaction, bool $isReversal, string $direction): void
    {
        $account = Account::findOrFail($accountId);

        $amount = $direction === 'from'
            ? ($isReversal ? $transaction->amount + $transaction->fee : -($transaction->amount + $transaction->fee))
            : ($isReversal ? -$transaction->amount : $transaction->amount);

        $newBalance = $account->balance + $amount;

        // Update the balance
        $account->update(['balance' => $newBalance]);

        Log::info('BalanceUpdateObserver: Account balance updated', [
            'account_id' => $account->id,
            'account_number' => $account->account_number,
            'previous_balance' => $account->balance - $amount,
            'new_balance' => $newBalance,
            'change_amount' => $amount,
            'direction' => $direction,
            'is_reversal' => $isReversal,
            'transaction_id' => $transaction->id
        ]);
    }

    private function updateParentAccountBalances(Transaction $transaction, bool $isReversal = false): void
    {
        $accountsToUpdate = [];

        if ($transaction->from_account_id) {
            $fromAccount = Account::findOrFail($transaction->from_account_id);
            if ($fromAccount->parent_account_id) {
                $accountsToUpdate[] = $fromAccount->parent_account_id;
            }
        }

        if ($transaction->to_account_id) {
            $toAccount = Account::findOrFail($transaction->to_account_id);
            if ($toAccount->parent_account_id) {
                $accountsToUpdate[] = $toAccount->parent_account_id;
            }
        }

        $accountsToUpdate = array_unique($accountsToUpdate);

        foreach ($accountsToUpdate as $parentId) {
            $this->recalculateParentAccountBalance($parentId, $transaction, $isReversal);
        }
    }

    private function recalculateParentAccountBalance(int $parentId, Transaction $transaction, bool $isReversal): void
    {
        $parentAccount = Account::findOrFail($parentId);

        // Get all child accounts and sum their balances
        $childBalances = Account::where('parent_account_id', $parentId)
            ->where('status', 'active')
            ->sum('balance');

        // Update parent balance
        $parentAccount->update(['balance' => $childBalances]);

        Log::info('BalanceUpdateObserver: Parent account balance recalculated', [
            'parent_account_id' => $parentAccount->id,
            'parent_account_number' => $parentAccount->account_number,
            'new_balance' => $childBalances,
            'child_count' => Account::where('parent_account_id', $parentId)->count(),
            'transaction_id' => $transaction->id,
            'is_reversal' => $isReversal
        ]);
    }

    private function updateUserBalances(Transaction $transaction, bool $isReversal = false): void
    {
        $usersToUpdate = [];

        if ($transaction->from_account_id) {
            $fromAccount = Account::findOrFail($transaction->from_account_id);
            $usersToUpdate = array_merge($usersToUpdate, $fromAccount->users->pluck('id')->toArray());
        }

        if ($transaction->to_account_id) {
            $toAccount = Account::findOrFail($transaction->to_account_id);
            $usersToUpdate = array_merge($usersToUpdate, $toAccount->users->pluck('id')->toArray());
        }

        $usersToUpdate = array_unique($usersToUpdate);

        foreach ($usersToUpdate as $userId) {
            $this->recalculateUserTotalBalance($userId, $transaction);
        }
    }

    private function recalculateUserTotalBalance(int $userId, Transaction $transaction): void
    {
        $user = User::findOrFail($userId);

        // Calculate total balance across all user accounts
        $totalBalance = Account::whereHas('users', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->where('status', 'active')
            ->sum('balance');

        // Update user's cached total balance
        $user->update(['total_balance' => $totalBalance]);

        Log::debug('BalanceUpdateObserver: User total balance recalculated', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'total_balance' => $totalBalance,
            'transaction_id' => $transaction->id
        ]);
    }

    private function updateCacheBalances(Transaction $transaction): void
    {
        if ($transaction->from_account_id) {
            $this->clearAccountCache($transaction->from_account_id);
        }

        if ($transaction->to_account_id) {
            $this->clearAccountCache($transaction->to_account_id);
        }

        // Clear user caches
        $this->clearUserCaches($transaction);
    }

    private function clearAccountCache(int $accountId): void
    {
        $keys = [
            "account_balance_{$accountId}",
            "account_details_{$accountId}",
            "account_transactions_{$accountId}"
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        Log::debug('BalanceUpdateObserver: Account cache cleared', [
            'account_id' => $accountId,
            'cache_keys' => $keys
        ]);
    }

    private function clearUserCaches(Transaction $transaction): void
    {
        $userIds = [];

        if ($transaction->from_account_id) {
            $fromAccount = Account::findOrFail($transaction->from_account_id);
            $userIds = array_merge($userIds, $fromAccount->users->pluck('id')->toArray());
        }

        if ($transaction->to_account_id) {
            $toAccount = Account::findOrFail($transaction->to_account_id);
            $userIds = array_merge($userIds, $toAccount->users->pluck('id')->toArray());
        }

        $userIds = array_unique($userIds);

        foreach ($userIds as $userId) {
            $this->clearUserCache($userId);
        }
    }

    private function clearUserCache(int $userId): void
    {
        $keys = [
            "user_balance_{$userId}",
            "user_accounts_{$userId}",
            "user_transactions_{$userId}"
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        Log::debug('BalanceUpdateObserver: User cache cleared', [
            'user_id' => $userId,
            'cache_keys' => $keys
        ]);
    }

    private function updateDailySummaries(Transaction $transaction, bool $isReversal = false): void
    {
        $date = now()->format('Y-m-d');

        if ($transaction->from_account_id) {
            $this->updateAccountDailySummary($transaction->from_account_id, $transaction, $date, $isReversal);
        }

        if ($transaction->to_account_id) {
            $this->updateAccountDailySummary($transaction->to_account_id, $transaction, $date, $isReversal);
        }
    }

    private function updateAccountDailySummary(int $accountId, Transaction $transaction, string $date, bool $isReversal): void
    {
        // This would typically update a daily_summary table
        // For now, we'll log the update
        Log::debug('BalanceUpdateObserver: Daily summary would be updated', [
            'account_id' => $accountId,
            'date' => $date,
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
            'is_reversal' => $isReversal
        ]);

        // In production, this would update a materialized view or summary table
        // Cache::put("daily_summary_{$accountId}_{$date}", [...], $this->cacheSettings['daily_summary_ttl']);
    }

    private function clearCacheBalances(Transaction $transaction): void
    {
        // Clear all relevant caches for reversal
        $this->updateCacheBalances($transaction);
    }

    public function getName(): string
    {
        return 'BalanceUpdateObserver';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }
}
