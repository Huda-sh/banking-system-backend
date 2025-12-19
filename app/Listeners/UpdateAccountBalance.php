<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Events\TransactionApproved;
use App\Events\TransactionFailed;
use App\Events\ScheduledTransactionExecuted;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class UpdateAccountBalance
{
    /**
     * Handle transaction created event.
     */
    public function handleTransactionCreated(TransactionCreated $event): void
    {
        // Don't update balances on creation, wait for completion/approval
    }

    /**
     * Handle transaction approved event.
     */
    public function handleTransactionApproved(TransactionApproved $event): void
    {
        $this->updateBalances($event->transaction);
    }

    /**
     * Handle transaction failed event.
     */
    public function handleTransactionFailed(TransactionFailed $event): void
    {
        // Don't update balances on failure
    }

    /**
     * Handle scheduled transaction executed event.
     */
    public function handleScheduledExecuted(ScheduledTransactionExecuted $event): void
    {
        $this->updateBalances($event->transaction);
    }

    /**
     * Update account balances for a transaction.
     */
    private function updateBalances($transaction): void
    {
        if (!$transaction->isCompleted()) {
            return;
        }

        try {
            DB::transaction(function () use ($transaction) {
                // Update source account balance
                if ($transaction->from_account_id) {
                    $this->updateAccountBalance($transaction->from_account_id, -$transaction->getTotalAmount());
                }

                // Update destination account balance
                if ($transaction->to_account_id) {
                    $this->updateAccountBalance($transaction->to_account_id, $transaction->amount);
                }

                // Update parent account balances if applicable
                $this->updateParentAccountBalances($transaction);

                Log::info('UpdateAccountBalance: Account balances updated', [
                    'transaction_id' => $transaction->id,
                    'from_account_id' => $transaction->from_account_id,
                    'to_account_id' => $transaction->to_account_id
                ]);
            });

        } catch (\Exception $e) {
            Log::error('UpdateAccountBalance: Failed to update account balances', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
        }
    }

    /**
     * Update a single account balance.
     */
    private function updateAccountBalance(int $accountId, float $amount): void
    {
        $account = Account::lockForUpdate()->find($accountId);

        if (!$account) {
            return;
        }

        $newBalance = $account->balance + $amount;
        $account->update(['balance' => $newBalance]);

        // Clear cache
        Cache::forget("account_balance_{$accountId}");
        Cache::forget("account_details_{$accountId}");
    }

    /**
     * Update parent account balances.
     */
    private function updateParentAccountBalances($transaction): void
    {
        $parentIds = [];

        if ($transaction->from_account_id) {
            $fromAccount = Account::find($transaction->from_account_id);
            if ($fromAccount && $fromAccount->parent_account_id) {
                $parentIds[] = $fromAccount->parent_account_id;
            }
        }

        if ($transaction->to_account_id) {
            $toAccount = Account::find($transaction->to_account_id);
            if ($toAccount && $toAccount->parent_account_id) {
                $parentIds[] = $toAccount->parent_account_id;
            }
        }

        $parentIds = array_unique($parentIds);

        foreach ($parentIds as $parentId) {
            $this->recalculateParentAccountBalance($parentId);
        }
    }

    /**
     * Recalculate parent account balance from children.
     */
    private function recalculateParentAccountBalance(int $parentId): void
    {
        $parentAccount = Account::lockForUpdate()->find($parentId);

        if (!$parentAccount) {
            return;
        }

        $totalBalance = Account::where('parent_account_id', $parentId)
            ->where('status', 'active')
            ->sum('balance');

        $parentAccount->update(['balance' => $totalBalance]);

        // Clear cache
        Cache::forget("account_balance_{$parentId}");
        Cache::forget("account_details_{$parentId}");

        // Recursively update grandparent accounts
        if ($parentAccount->parent_account_id) {
            $this->recalculateParentAccountBalance($parentAccount->parent_account_id);
        }
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe($events): void
    {
        $events->listen(
            TransactionCreated::class,
            [UpdateAccountBalance::class, 'handleTransactionCreated']
        );

        $events->listen(
            TransactionApproved::class,
            [UpdateAccountBalance::class, 'handleTransactionApproved']
        );

        $events->listen(
            TransactionFailed::class,
            [UpdateAccountBalance::class, 'handleTransactionFailed']
        );

        $events->listen(
            ScheduledTransactionExecuted::class,
            [UpdateAccountBalance::class, 'handleScheduledExecuted']
        );
    }
}
