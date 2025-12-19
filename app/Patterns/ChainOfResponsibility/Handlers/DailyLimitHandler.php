<?php

namespace App\Patterns\ChainOfResponsibility\Handlers;

use App\Models\Transaction;
use App\Models\User;
use App\Enums\TransactionType;
use App\Exceptions\DailyLimitExceededException;
use App\Patterns\ChainOfResponsibility\Interfaces\TransactionHandler;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DailyLimitHandler implements TransactionHandler
{
    /**
     * Daily transaction limits by account type and user role.
     */
    const DAILY_LIMITS = [
        'individual' => [
            'basic' => 10000.00,    // $10K for basic accounts
            'premium' => 50000.00,  // $50K for premium accounts
            'business' => 100000.00 // $100K for business accounts
        ],
        'business' => [
            'standard' => 50000.00,
            'enterprise' => 500000.00
        ],
        'default' => 25000.00 // $25K default limit
    ];

    /**
     * Cache TTL in minutes for daily transaction totals.
     */
    const CACHE_TTL = 15;

    private ?TransactionHandler $next = null;

    public function setNext(TransactionHandler $handler): TransactionHandler
    {
        $this->next = $handler;
        return $handler;
    }

    public function handle(Transaction $transaction): bool
    {
        Log::debug('DailyLimitHandler: Checking daily transaction limits', [
            'transaction_id' => $transaction->id,
            'user_id' => $transaction->initiated_by,
            'amount' => $transaction->amount
        ]);

        try {
            $this->checkDailyLimits($transaction);

            Log::debug('DailyLimitHandler: Daily limit check passed', [
                'transaction_id' => $transaction->id
            ]);

            return $this->next ? $this->next->handle($transaction) : true;

        } catch (DailyLimitExceededException $e) {
            Log::warning('DailyLimitHandler: Daily limit exceeded', [
                'transaction_id' => $transaction->id,
                'user_id' => $transaction->initiated_by,
                'amount' => $transaction->amount,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('DailyLimitHandler: Unexpected error', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw $e;
        }
    }

    private function checkDailyLimits(Transaction $transaction): void
    {
        $user = User::findOrFail($transaction->initiated_by);
        $account = $transaction->from_account_id
            ? Account::findOrFail($transaction->from_account_id)
            : null;

        if (!$account) {
            return; // No daily limit check for pure deposits
        }

        // Get daily limit for this user/account
        $dailyLimit = $this->getDailyLimit($user, $account);

        if ($dailyLimit <= 0) {
            return; // No limit set
        }

        // Get today's transaction total
        $todayTotal = $this->getTodayTransactionTotal($user, $account, $transaction->currency);

        // Calculate new total if this transaction is processed
        $newTotal = $todayTotal + $transaction->amount;

        if ($newTotal > $dailyLimit) {
            $remaining = max(0, $dailyLimit - $todayTotal);
            throw new DailyLimitExceededException(
                sprintf('Daily transaction limit exceeded. Limit: %.2f %s, Used: %.2f %s, Remaining: %.2f %s, Transaction: %.2f %s',
                    $dailyLimit, $transaction->currency,
                    $todayTotal, $transaction->currency,
                    $remaining, $transaction->currency,
                    $transaction->amount, $transaction->currency
                )
            );
        }
    }

    private function getDailyLimit(User $user, Account $account): float
    {
        try {
            // Check if user has a custom daily limit
            if ($user->daily_transaction_limit > 0) {
                return $user->daily_transaction_limit;
            }

            // Check account features for limit overrides
            if ($account->hasFeature('high_daily_limit')) {
                return 100000.00; // $100K for high limit accounts
            }

            // Determine account type category
            $accountType = $account->accountType->name ?? 'default';
            $accountCategory = $this->getAccountCategory($accountType);

            // Get limit based on account category and type
            $limits = self::DAILY_LIMITS[$accountCategory] ?? self::DAILY_LIMITS['default'];

            if (is_array($limits)) {
                $accountSubType = $this->getAccountSubType($accountType);
                return $limits[$accountSubType] ?? self::DAILY_LIMITS['default'];
            }

            return $limits;

        } catch (\Exception $e) {
            Log::error('Error getting daily limit', [
                'user_id' => $user->id,
                'account_id' => $account->id,
                'error' => $e->getMessage()
            ]);

            // Fallback to default limit
            return self::DAILY_LIMITS['default'];
        }
    }

    private function getAccountCategory(string $accountType): string
    {
        $accountType = strtolower($accountType);

        if (str_contains($accountType, 'business') || str_contains($accountType, 'corporate')) {
            return 'business';
        }

        if (str_contains($accountType, 'premium') || str_contains($accountType, 'gold') || str_contains($accountType, 'platinum')) {
            return 'individual';
        }

        return 'default';
    }

    private function getAccountSubType(string $accountType): string
    {
        $accountType = strtolower($accountType);

        if (str_contains($accountType, 'premium') || str_contains($accountType, 'gold') || str_contains($accountType, 'platinum')) {
            return 'premium';
        }

        if (str_contains($accountType, 'business') || str_contains($accountType, 'corporate')) {
            return 'enterprise';
        }

        return 'basic';
    }

    private function getTodayTransactionTotal(User $user, Account $account, string $currency): float
    {
        $cacheKey = "daily_transaction_total:{$user->id}:{$account->id}:{$currency}:" . now()->format('Y-m-d');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user, $account, $currency) {
            return $this->calculateTodayTransactionTotal($user, $account, $currency);
        });
    }

    private function calculateTodayTransactionTotal(User $user, Account $account, string $currency): float
    {
        $startDate = now()->startOfDay();
        $endDate = now()->endOfDay();

        $completedTransactions = Transaction::where('initiated_by', $user->id)
            ->where('from_account_id', $account->id)
            ->whereIn('type', [TransactionType::WITHDRAWAL, TransactionType::TRANSFER])
            ->where('status', 'completed')
            ->where('currency', $currency)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');

        $pendingTransactions = Transaction::where('initiated_by', $user->id)
            ->where('from_account_id', $account->id)
            ->whereIn('type', [TransactionType::WITHDRAWAL, TransactionType::TRANSFER])
            ->whereIn('status', ['pending', 'pending_approval'])
            ->where('currency', $currency)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');

        return $completedTransactions + $pendingTransactions;
    }

    public function getName(): string
    {
        return 'DailyLimitHandler';
    }

    public function getPriority(): int
    {
        return 30; // Medium priority - after basic validations
    }
}
