<?php

namespace App\Patterns\ChainOfResponsibility\Handlers;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Account;
use App\Enums\TransactionType;
use App\Exceptions\FraudRiskException;
use App\Patterns\ChainOfResponsibility\Interfaces\TransactionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;

class FraudDetectionHandler implements TransactionHandler
{
    /**
     * Risk scoring thresholds.
     */
    const RISK_THRESHOLDS = [
        'low' => 30,      // Below 30: Low risk
        'medium' => 70,   // 30-70: Medium risk, manual review
        'high' => 100     // Above 70: High risk, block transaction
    ];

    /**
     * Risk factors and their weights.
     */
    const RISK_FACTORS = [
        'unusual_location' => 25,
        'large_amount' => 30,
        'new_payee' => 20,
        'after_hours' => 15,
        'rapid_succession' => 25,
        'high_risk_country' => 40,
        'account_age' => 10,
        'unusual_pattern' => 35
    ];

    /**
     * High-risk countries (ISO 3166-1 alpha-2 codes).
     */
    const HIGH_RISK_COUNTRIES = ['IR', 'SY', 'CU', 'KP', 'RU', 'BY'];

    private ?TransactionHandler $next = null;

    public function setNext(TransactionHandler $handler): TransactionHandler
    {
        $this->next = $handler;
        return $handler;
    }

    public function handle(Transaction $transaction): bool
    {
        Log::debug('FraudDetectionHandler: Analyzing transaction for fraud risk', [
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
            'type' => $transaction->type->value
        ]);

        try {
            $riskScore = $this->calculateRiskScore($transaction);

            Log::info('FraudDetectionHandler: Risk score calculated', [
                'transaction_id' => $transaction->id,
                'risk_score' => $riskScore,
                'risk_level' => $this->getRiskLevel($riskScore)
            ]);

            if ($riskScore >= self::RISK_THRESHOLDS['high']) {
                throw new FraudRiskException(
                    "High fraud risk detected. Risk score: {$riskScore}",
                    $riskScore
                );
            }

            if ($riskScore >= self::RISK_THRESHOLDS['medium']) {
                Log::warning('FraudDetectionHandler: Medium risk detected - requires approval', [
                    'transaction_id' => $transaction->id,
                    'risk_score' => $riskScore
                ]);
                return false; // Requires approval
            }

            return $this->next ? $this->next->handle($transaction) : true;

        } catch (FraudRiskException $e) {
            Log::error('FraudDetectionHandler: High fraud risk detected', [
                'transaction_id' => $transaction->id,
                'risk_score' => $e->getRiskScore(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('FraudDetectionHandler: Unexpected error', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            // Fail safe - don't block transaction on fraud detection failure
            return $this->next ? $this->next->handle($transaction) : true;
        }
    }

    private function calculateRiskScore(Transaction $transaction): int
    {
        $riskScore = 0;
        $riskFactors = [];

        // Get transaction details
        $user = User::findOrFail($transaction->initiated_by);
        $fromAccount = $transaction->from_account_id ? Account::findOrFail($transaction->from_account_id) : null;
        $toAccount = Account::findOrFail($transaction->to_account_id);

        // 1. Check for unusual location
        if ($this->isUnusualLocation($user, $transaction)) {
            $riskScore += self::RISK_FACTORS['unusual_location'];
            $riskFactors[] = 'unusual_location';
        }

        // 2. Check for large amount
        if ($this->isLargeAmount($transaction, $user)) {
            $riskScore += self::RISK_FACTORS['large_amount'];
            $riskFactors[] = 'large_amount';
        }

        // 3. Check for new payee
        if ($fromAccount && $this->isNewPayee($fromAccount, $toAccount)) {
            $riskScore += self::RISK_FACTORS['new_payee'];
            $riskFactors[] = 'new_payee';
        }

        // 4. Check for after-hours transaction
        if ($this->isAfterHours()) {
            $riskScore += self::RISK_FACTORS['after_hours'];
            $riskFactors[] = 'after_hours';
        }

        // 5. Check for rapid succession of transactions
        if ($user && $this->isRapidSuccession($user, $transaction)) {
            $riskScore += self::RISK_FACTORS['rapid_succession'];
            $riskFactors[] = 'rapid_succession';
        }

        // 6. Check for high-risk country
        if ($this->isHighRiskCountry($toAccount)) {
            $riskScore += self::RISK_FACTORS['high_risk_country'];
            $riskFactors[] = 'high_risk_country';
        }

        // 7. Check account age
        if ($fromAccount && $this->isYoungAccount($fromAccount)) {
            $riskScore += self::RISK_FACTORS['account_age'];
            $riskFactors[] = 'account_age';
        }

        // 8. Check unusual transaction pattern
        if ($user && $this->isUnusualPattern($user, $transaction)) {
            $riskScore += self::RISK_FACTORS['unusual_pattern'];
            $riskFactors[] = 'unusual_pattern';
        }

        Log::debug('FraudDetectionHandler: Risk factors identified', [
            'transaction_id' => $transaction->id,
            'risk_factors' => $riskFactors,
            'risk_score' => $riskScore
        ]);

        return min(100, $riskScore); // Cap at 100
    }

    private function isUnusualLocation(User $user, Transaction $transaction): bool
    {
        $userLocation = $user->last_login_location ?? $this->getGeoLocationFromIP($user->last_login_ip);
        $transactionLocation = $this->getGeoLocationFromIP($transaction->ip_address);

        if (!$userLocation || !$transactionLocation) {
            return false;
        }

        // Check if locations are in different countries
        return $userLocation['country_code'] !== $transactionLocation['country_code'];
    }

    private function getGeoLocationFromIP(?string $ipAddress): ?array
    {
        if (!$ipAddress || $ipAddress === 'system') {
            return null;
        }

        try {
            // In production, use a real geolocation service like MaxMind
            // This is a mock implementation
            $mockLocations = [
                '192.168.1.1' => ['country_code' => 'US', 'city' => 'New York'],
                '10.0.0.1' => ['country_code' => 'UK', 'city' => 'London'],
                '172.16.0.1' => ['country_code' => 'DE', 'city' => 'Berlin']
            ];

            return $mockLocations[$ipAddress] ?? ['country_code' => 'US', 'city' => 'Unknown'];

        } catch (\Exception $e) {
            Log::error('Geolocation lookup failed', [
                'ip_address' => $ipAddress,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function isLargeAmount(Transaction $transaction, User $user): bool
    {
        // Get user's average transaction amount
        $avgTransaction = $this->getUserAverageTransactionAmount($user, $transaction->currency);

        // Large amount threshold: 5x average or $10,000+
        $threshold = max($avgTransaction * 5, 10000.00);

        return $transaction->amount >= $threshold;
    }

    private function getUserAverageTransactionAmount(User $user, string $currency): float
    {
        $startDate = now()->subMonths(3);

        $avgAmount = Transaction::where('initiated_by', $user->id)
            ->where('currency', $currency)
            ->where('status', 'completed')
            ->where('created_at', '>=', $startDate)
            ->avg('amount');

        return $avgAmount ?? 500.00; // Default average if no history
    }

    private function isNewPayee(Account $fromAccount, Account $toAccount): bool
    {
        $recentPayees = $fromAccount->transactions()
            ->where('to_account_id', $toAccount->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subMonths(6))
            ->count();

        return $recentPayees === 0;
    }

    private function isAfterHours(): bool
    {
        $hour = now()->hour;
        $dayOfWeek = now()->dayOfWeek;

        // After hours: 10 PM to 6 AM, or weekends
        return ($hour >= 22 || $hour < 6) || ($dayOfWeek === 0 || $dayOfWeek === 6);
    }

    private function isRapidSuccession(User $user, Transaction $transaction): bool
    {
        $recentTransactions = Transaction::where('initiated_by', $user->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count();

        // More than 3 transactions in 15 minutes is suspicious
        return $recentTransactions > 3;
    }

    private function isHighRiskCountry(Account $account): bool
    {
        // In production, this would check the account's country or IP geolocation
        // For now, we'll use a mock based on account metadata
        $accountCountry = $account->metadata['country_code'] ?? 'US';

        return in_array($accountCountry, self::HIGH_RISK_COUNTRIES);
    }

    private function isYoungAccount(Account $account): bool
    {
        return $account->created_at->diffInDays(now()) < 30; // Less than 30 days old
    }

    private function isUnusualPattern(User $user, Transaction $transaction): bool
    {
        // Get user's typical transaction types
        $typicalTypes = Transaction::where('initiated_by', $user->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subMonths(3))
            ->select('type')
            ->groupBy('type')
            ->orderByRaw('COUNT(*) DESC')
            ->pluck('type')
            ->take(3)
            ->toArray();

        // Check if current transaction type is unusual for this user
        return !in_array($transaction->type->value, $typicalTypes);
    }

    private function getRiskLevel(int $riskScore): string
    {
        return match(true) {
            $riskScore >= self::RISK_THRESHOLDS['high'] => 'high',
            $riskScore >= self::RISK_THRESHOLDS['medium'] => 'medium',
            default => 'low'
        };
    }

    public function getName(): string
    {
        return 'FraudDetectionHandler';
    }

    public function getPriority(): int
    {
        return 40; // Medium-high priority - important for security
    }
}
