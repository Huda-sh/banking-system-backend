<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\TransactionService;
use App\Exceptions\DailyLimitExceededException;
use App\Exceptions\TransactionException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckTransactionLimits
{
    public function __construct(
        private TransactionService $transactionService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            return $next($request);
        }

        try {
            $this->validateTransactionLimits($request);
            return $next($request);

        } catch (DailyLimitExceededException $e) {
            Log::warning('Transaction limit exceeded', [
                'user_id' => $request->user()->id,
                'ip' => $request->ip(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'DAILY_LIMIT_EXCEEDED',
                'details' => $e->getDetails()
            ], 429);

        } catch (TransactionException $e) {
            Log::warning('Transaction validation failed', [
                'user_id' => $request->user()->id,
                'ip' => $request->ip(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'VALIDATION_FAILED'
            ], 422);

        } catch (\Exception $e) {
            Log::error('Transaction limit check failed', [
                'user_id' => $request->user()->id,
                'ip' => $request->ip(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to validate transaction limits',
                'error_code' => 'SERVER_ERROR'
            ], 500);
        }
    }

    private function validateTransactionLimits(Request $request): void
    {
        $transactionData = $request->only([
            'type', 'amount', 'currency', 'from_account_id', 'to_account_id'
        ]);

        // Get the transaction type from the route or request
        if (!$transactionData['type'] && $request->route()) {
            $routeAction = $request->route()->getAction();
            if (isset($routeAction['transactionType'])) {
                $transactionData['type'] = $routeAction['transactionType'];
            }
        }

        // Calculate daily limits
        $user = $request->user();
        $accountId = $transactionData['from_account_id'] ?? null;
        $currency = $transactionData['currency'] ?? 'USD';

        $todayTotal = $this->getTodayTransactionTotal($user, $accountId, $currency);
        $limit = $this->getDailyLimit($user, $accountId);

        $newTotal = $todayTotal + ($transactionData['amount'] ?? 0);

        if ($newTotal > $limit) {
            $remaining = max(0, $limit - $todayTotal);
            throw new DailyLimitExceededException(
                sprintf('Daily transaction limit exceeded. Limit: %.2f %s, Used: %.2f %s, Remaining: %.2f %s, Transaction: %.2f %s',
                    $limit, $currency,
                    $todayTotal, $currency,
                    $remaining, $currency,
                    $transactionData['amount'] ?? 0, $currency
                ),
                [
                    'daily_limit' => $limit,
                    'today_total' => $todayTotal,
                    'remaining' => $remaining,
                    'transaction_amount' => $transactionData['amount'] ?? 0,
                    'currency' => $currency
                ]
            );
        }
    }

    private function getTodayTransactionTotal($user, $accountId, $currency): float
    {
        // This would typically use a service or repository
        // For middleware, we'll use a simplified version
        return 0.0; // In production, this would query the database
    }

    private function getDailyLimit($user, $accountId): float
    {
        // Default limit
        $limit = 25000.00;

        // Get user-specific limit
        if ($user->daily_transaction_limit > 0) {
            $limit = $user->daily_transaction_limit;
        }

        // Get account-specific limit
        if ($accountId) {
            // In production, this would query the account
            $limit = max($limit, 10000.00);
        }

        return $limit;
    }
}
