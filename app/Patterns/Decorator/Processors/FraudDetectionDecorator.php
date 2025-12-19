<?php

namespace App\Patterns\Decorator\Processors;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Account;
use App\Enums\TransactionStatus;
use App\Exceptions\ProcessorException;
use App\Exceptions\FraudRiskException;
use App\Patterns\Decorator\Interfaces\TransactionProcessor;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FraudDetectionDecorator implements TransactionProcessor
{
    private TransactionProcessor $processor;
    private bool $enabled = true;
    private array $riskThresholds = [
        'low' => 30,
        'medium' => 70,
        'high' => 100
    ];
    private array $riskFactors = [
        'unusual_location' => 25,
        'large_amount' => 30,
        'new_payee' => 20,
        'after_hours' => 15,
        'rapid_succession' => 25,
        'high_risk_country' => 40,
        'account_age' => 10,
        'unusual_pattern' => 35
    ];
    private array $highRiskCountries = ['IR', 'SY', 'CU', 'KP', 'RU', 'BY'];

    public function __construct(TransactionProcessor $processor)
    {
        $this->processor = $processor;
        $this->enabled = config('banking.processors.fraud_detection.enabled', true);
    }

    public function process(Transaction $transaction): bool
    {
        if (!$this->isEnabled()) {
            return $this->processor->process($transaction);
        }

        try {
            Log::debug('FraudDetectionDecorator: Analyzing transaction for fraud risk', [
                'transaction_id' => $transaction->id,
                'amount' => $transaction->amount,
                'type' => $transaction->type->value
            ]);

            $riskScore = $this->calculateRiskScore($transaction);

            Log::info('FraudDetectionDecorator: Risk score calculated', [
                'transaction_id' => $transaction->id,
                'risk_score' => $riskScore,
                'risk_level' => $this->getRiskLevel($riskScore)
            ]);

            // Handle based on risk level
            if ($riskScore >= $this->riskThresholds['high']) {
                $this->handleHighRiskTransaction($transaction, $riskScore);
                return false;
            }

            if ($riskScore >= $this->riskThresholds['medium']) {
                $this->handleMediumRiskTransaction($transaction, $riskScore);
                // Continue processing but flag for review
            }

            // Log the risk analysis
            $this->logRiskAnalysis($transaction, $riskScore);

            return $this->processor->process($transaction);

        } catch (FraudRiskException $e) {
            Log::error('FraudDetectionDecorator: Fraud risk detected', [
                'transaction_id' => $transaction->id,
                'risk_score' => $e->getRiskScore(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('FraudDetectionDecorator: Error during fraud detection', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            // Fail safe - continue processing if fraud detection fails
            return $this->processor->process($transaction);
        }
    }

    private function calculateRiskScore(Transaction $transaction): int
    {
        $riskScore = 0;
        $riskFactors = [];

        $user = User::findOrFail($transaction->initiated_by);
        $fromAccount = $transaction->from_account_id ? Account::findOrFail($transaction->from_account_id) : null;
        $toAccount = Account::findOrFail($transaction->to_account_id);

        // 1. Unusual location risk
        if ($this->isUnusualLocation($user, $transaction)) {
            $riskScore += $this->riskFactors['unusual_location'];
            $riskFactors[] = 'unusual_location';
        }

        // 2. Large amount risk
        if ($this->isLargeAmount($transaction, $user)) {
            $riskScore += $this->riskFactors['large_amount'];
            $riskFactors[] = 'large_amount';
        }

        // 3. New payee risk
        if ($fromAccount && $this->isNewPayee($fromAccount, $toAccount)) {
            $riskScore += $this->riskFactors['new_payee'];
            $riskFactors[] = 'new_payee';
        }

        // 4. After hours risk
        if ($this->isAfterHours()) {
            $riskScore += $this->riskFactors['after_hours'];
            $riskFactors[] = 'after_hours';
        }

        // 5. Rapid succession risk
        if ($this->isRapidSuccession($user, $transaction)) {
            $riskScore += $this->riskFactors['rapid_succession'];
            $riskFactors[] = 'rapid_succession';
        }

        // 6. High risk country risk
        if ($this->isHighRiskCountry($toAccount)) {
            $riskScore += $this->riskFactors['high_risk_country'];
            $riskFactors[] = 'high_risk_country';
        }

        // 7. Young account risk
        if ($fromAccount && $this->isYoungAccount($fromAccount)) {
            $riskScore += $this->riskFactors['account_age'];
            $riskFactors[] = 'account_age';
        }

        // 8. Unusual pattern risk
        if ($this->isUnusualPattern($user, $transaction)) {
            $riskScore += $this->riskFactors['unusual_pattern'];
            $riskFactors[] = 'unusual_pattern';
        }

        Log::debug('FraudDetectionDecorator: Risk factors identified', [
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

        return $userLocation['country_code'] !== $transactionLocation['country_code'];
    }

    private function getGeoLocationFromIP(?string $ipAddress): ?array
    {
        if (!$ipAddress || $ipAddress === 'system') {
            return null;
        }

        // In production, this would use a real geolocation service
        $mockLocations = [
            '192.168.1.1' => ['country_code' => 'US', 'city' => 'New York'],
            '10.0.0.1' => ['country_code' => 'UK', 'city' => 'London'],
            '172.16.0.1' => ['country_code' => 'DE', 'city' => 'Berlin']
        ];

        return $mockLocations[$ipAddress] ?? ['country_code' => 'US', 'city' => 'Unknown'];
    }

    private function isLargeAmount(Transaction $transaction, User $user): bool
    {
        $avgTransaction = $this->getUserAverageTransactionAmount($user, $transaction->currency);
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
        return $avgAmount ?? 500.00;
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
        return ($hour >= 22 || $hour < 6) || ($dayOfWeek === 0 || $dayOfWeek === 6);
    }

    private function isRapidSuccession(User $user, Transaction $transaction): bool
    {
        $recentTransactions = Transaction::where('initiated_by', $user->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count();
        return $recentTransactions > 3;
    }

    private function isHighRiskCountry(Account $account): bool
    {
        $accountCountry = $account->metadata['country_code'] ?? 'US';
        return in_array($accountCountry, $this->highRiskCountries);
    }

    private function isYoungAccount(Account $account): bool
    {
        return $account->created_at->diffInDays(now()) < 30;
    }

    private function isUnusualPattern(User $user, Transaction $transaction): bool
    {
        $typicalTypes = Transaction::where('initiated_by', $user->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subMonths(3))
            ->select('type')
            ->groupBy('type')
            ->orderByRaw('COUNT(*) DESC')
            ->pluck('type')
            ->take(3)
            ->toArray();
        return !in_array($transaction->type->value, $typicalTypes);
    }

    private function handleHighRiskTransaction(Transaction $transaction, int $riskScore): void
    {
        Log::critical('FraudDetectionDecorator: High risk transaction blocked', [
            'transaction_id' => $transaction->id,
            'risk_score' => $riskScore,
            'amount' => $transaction->amount
        ]);

        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'fraud_blocked' => true,
                'risk_score' => $riskScore,
                'blocked_at' => now()->format('Y-m-d H:i:s'),
                'blocked_reason' => 'High fraud risk detected'
            ])
        ]);

        throw new FraudRiskException("Transaction blocked due to high fraud risk (score: {$riskScore})", $riskScore);
    }

    private function handleMediumRiskTransaction(Transaction $transaction, int $riskScore): void
    {
        Log::warning('FraudDetectionDecorator: Medium risk transaction flagged for review', [
            'transaction_id' => $transaction->id,
            'risk_score' => $riskScore
        ]);

        $transaction->update([
            'metadata' => array_merge($transaction->metadata ?? [], [
                'fraud_flagged' => true,
                'risk_score' => $riskScore,
                'flagged_at' => now()->format('Y-m-d H:i:s'),
                'flagged_reason' => 'Medium fraud risk detected'
            ])
        ]);
    }

    private function logRiskAnalysis(Transaction $transaction, int $riskScore): void
    {
        $transaction->update([
            'metadata' => array_merge($transaction->metadata ?? [], [
                'fraud_analysis' => [
                    'risk_score' => $riskScore,
                    'risk_level' => $this->getRiskLevel($riskScore),
                    'analyzed_at' => now()->format('Y-m-d H:i:s'),
                    'processor' => $this->getName()
                ]
            ])
        ]);
    }

    private function getRiskLevel(int $riskScore): string
    {
        return match(true) {
            $riskScore >= $this->riskThresholds['high'] => 'high',
            $riskScore >= $this->riskThresholds['medium'] => 'medium',
            default => 'low'
        };
    }

    public function validate(Transaction $transaction): bool
    {
        // Fraud detection doesn't perform validation, it just analyzes risk
        return $this->processor->validate($transaction);
    }

    public function getName(): string
    {
        return 'FraudDetectionDecorator';
    }

    public function getMetadata(): array
    {
        return [
            'name' => $this->getName(),
            'enabled' => $this->isEnabled(),
            'risk_thresholds' => $this->riskThresholds,
            'risk_factors' => $this->riskFactors,
            'high_risk_countries' => $this->highRiskCountries
        ];
    }

    public function setContext(array $context): self
    {
        $this->processor->setContext($context);
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && config('services.fraud_detection.enabled', true);
    }
}
