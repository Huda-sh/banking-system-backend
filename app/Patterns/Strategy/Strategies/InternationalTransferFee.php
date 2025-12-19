<?php

namespace App\Patterns\Strategy\Strategies;

use App\Models\Account;
use App\Models\Transaction;
use App\Patterns\Strategy\Interfaces\FeeCalculationStrategy;
use App\Exceptions\StrategyException;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class InternationalTransferFee implements FeeCalculationStrategy
{
    /**
     * Base fee rate for international transfers (3%).
     */
    const BASE_FEE_RATE = 0.03;

    /**
     * Minimum fee amount for international transfers.
     */
    const MINIMUM_FEE = 10.00;

    /**
     * Maximum fee amount for international transfers.
     */
    const MAXIMUM_FEE = 100.00;

    /**
     * Fixed wire transfer fee component.
     */
    const WIRE_TRANSFER_FEE = 25.00;

    /**
     * High-risk country surcharge rates.
     */
    const HIGH_RISK_SURCHARGE_RATES = [
        'standard' => 0.02,    // 2% surcharge
        'high' => 0.05,        // 5% surcharge
        'very_high' => 0.10    // 10% surcharge
    ];

    /**
     * High-risk country codes (ISO 3166-1 alpha-2).
     */
    const HIGH_RISK_COUNTRIES = [
        'standard' => ['RU', 'BY', 'VE'],
        'high' => ['IR', 'SY', 'CU', 'KP'],
        'very_high' => ['AF', 'YE', 'SO']
    ];

    public function calculateFee(
        float $amount,
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): float {
        try {
            Log::debug('InternationalTransferFee: Calculating fee for international transfer', [
                'amount' => $amount,
                'from_account_id' => $fromAccount?->id,
                'to_account_id' => $toAccount?->id,
                'context' => $context
            ]);

            // Calculate base fee
            $baseFee = $amount * self::BASE_FEE_RATE;

            // Add wire transfer fee
            $totalFee = $baseFee + self::WIRE_TRANSFER_FEE;

            // Apply high-risk country surcharge if applicable
            $surcharge = $this->calculateRiskSurcharge($amount, $toAccount, $context);
            $totalFee += $surcharge;

            // Apply account-specific discounts
            $discountedFee = $this->applyAccountDiscounts($totalFee, $fromAccount, $toAccount, $context);

            // Apply minimum and maximum fee constraints
            $finalFee = $this->applyFeeConstraints($discountedFee);

            Log::debug('InternationalTransferFee: Fee calculation completed', [
                'base_fee' => $baseFee,
                'wire_fee' => self::WIRE_TRANSFER_FEE,
                'surcharge' => $surcharge,
                'discounted_fee' => $discountedFee,
                'final_fee' => $finalFee
            ]);

            return $finalFee;

        } catch (\Exception $e) {
            Log::error('InternationalTransferFee: Fee calculation failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'amount' => $amount
            ]);
            throw new StrategyException("International transfer fee calculation failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function calculateRiskSurcharge(float $amount, ?Account $toAccount, array $context): float
    {
        $countryCode = $this->getDestinationCountry($toAccount, $context);

        if (!$countryCode) {
            return 0.0;
        }

        foreach (self::HIGH_RISK_SURCHARGE_RATES as $riskLevel => $rate) {
            if (in_array($countryCode, self::HIGH_RISK_COUNTRIES[$riskLevel])) {
                $surcharge = $amount * $rate;
                Log::warning('InternationalTransferFee: High-risk country surcharge applied', [
                    'country_code' => $countryCode,
                    'risk_level' => $riskLevel,
                    'surcharge_rate' => $rate,
                    'surcharge_amount' => $surcharge
                ]);
                return $surcharge;
            }
        }

        return 0.0;
    }

    private function getDestinationCountry(?Account $toAccount, array $context): ?string
    {
        // Try to get from account metadata
        if ($toAccount && isset($toAccount->metadata['country_code'])) {
            return $toAccount->metadata['country_code'];
        }

        // Try to get from context
        if (isset($context['destination_country'])) {
            return $context['destination_country'];
        }

        // Try to get from transaction context
        if (isset($context['transaction']) && $context['transaction'] instanceof Transaction) {
            $transaction = $context['transaction'];
            if (isset($transaction->metadata['destination_country'])) {
                return $transaction->metadata['destination_country'];
            }
        }

        return null;
    }

    private function applyAccountDiscounts(float $baseFee, ?Account $fromAccount, ?Account $toAccount, array $context): float
    {
        $discountedFee = $baseFee;

        // Premium account discount
        if ($fromAccount && $fromAccount->hasFeature('premium_account')) {
            $discountedFee *= 0.7; // 30% discount for premium accounts
            Log::debug('InternationalTransferFee: Premium account discount applied', [
                'original_fee' => $baseFee,
                'discounted_fee' => $discountedFee
            ]);
        }

        // Corporate account discount
        if ($fromAccount && $this->isCorporateAccount($fromAccount)) {
            $discountedFee *= 0.85; // 15% discount for corporate accounts
            Log::debug('InternationalTransferFee: Corporate account discount applied', [
                'original_fee' => $baseFee,
                'discounted_fee' => $discountedFee
            ]);
        }

        // Volume discount for frequent international transfers
        if ($fromAccount && $this->isFrequentInternationalUser($fromAccount)) {
            $discountedFee *= 0.95; // 5% discount for frequent users
        }

        return $discountedFee;
    }

    private function isCorporateAccount(Account $account): bool
    {
        return in_array($account->accountType->name ?? '', [
            'Business Checking',
            'Corporate Account',
            'Business Savings'
        ]);
    }

    private function isFrequentInternationalUser(Account $account): bool
    {
        // In production, this would check transaction history
        // For now, we'll use a simple check
        $lastMonth = now()->subMonth();

        $internationalCount = Transaction::where('from_account_id', $account->id)
            ->where('type', 'international_transfer')
            ->where('created_at', '>=', $lastMonth)
            ->count();

        return $internationalCount >= 5;
    }

    private function applyFeeConstraints(float $fee): float
    {
        // Apply minimum fee
        if ($fee < self::MINIMUM_FEE) {
            return self::MINIMUM_FEE;
        }

        // Apply maximum fee
        if (self::MAXIMUM_FEE !== null && $fee > self::MAXIMUM_FEE) {
            return self::MAXIMUM_FEE;
        }

        return $fee;
    }

    public function getName(): string
    {
        return 'InternationalTransferFee';
    }

    public function getDescription(): string
    {
        return 'Fee calculation strategy for international transfers between accounts in different currencies, including wire transfer fees and risk surcharges';
    }

    public function getFeeBreakdown(
        float $amount,
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): array {
        $baseFee = $amount * self::BASE_FEE_RATE;
        $wireFee = self::WIRE_TRANSFER_FEE;
        $surcharge = $this->calculateRiskSurcharge($amount, $toAccount, $context);
        $totalFee = $baseFee + $wireFee + $surcharge;
        $discountedFee = $this->applyAccountDiscounts($totalFee, $fromAccount, $toAccount, $context);
        $finalFee = $this->applyFeeConstraints($discountedFee);

        $breakdown = [
            'strategy' => $this->getName(),
            'base_rate' => self::BASE_FEE_RATE,
            'base_fee' => $baseFee,
            'wire_transfer_fee' => $wireFee,
            'risk_surcharge' => $surcharge,
            'total_before_discounts' => $totalFee,
            'discounts' => [],
            'minimum_fee' => self::MINIMUM_FEE,
            'maximum_fee' => self::MAXIMUM_FEE,
            'final_fee' => $finalFee
        ];

        // Add discount details if applicable
        if ($totalFee > $discountedFee) {
            $discountAmount = $totalFee - $discountedFee;
            $breakdown['discounts'][] = [
                'type' => 'account_discounts',
                'amount' => $discountAmount,
                'percentage' => ($discountAmount / $totalFee) * 100
            ];
        }

        // Add risk surcharge details
        $countryCode = $this->getDestinationCountry($toAccount, $context);
        if ($countryCode && $surcharge > 0) {
            $breakdown['risk_details'] = [
                'country_code' => $countryCode,
                'risk_level' => $this->getRiskLevel($countryCode),
                'surcharge_rate' => $this->getSurchargeRate($countryCode)
            ];
        }

        return $breakdown;
    }

    private function getRiskLevel(string $countryCode): string
    {
        foreach (self::HIGH_RISK_COUNTRIES as $riskLevel => $countries) {
            if (in_array($countryCode, $countries)) {
                return $riskLevel;
            }
        }
        return 'none';
    }

    private function getSurchargeRate(string $countryCode): float
    {
        foreach (self::HIGH_RISK_COUNTRIES as $riskLevel => $countries) {
            if (in_array($countryCode, $countries)) {
                return self::HIGH_RISK_SURCHARGE_RATES[$riskLevel];
            }
        }
        return 0.0;
    }

    public function isApplicable(
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): bool {
        // Check if this is an international transfer (different currencies)
        if ($fromAccount && $toAccount) {
            return $fromAccount->currency !== $toAccount->currency;
        }

        // Check context for transfer type
        return ($context['transfer_type'] ?? 'international') === 'international';
    }

    public function getMinimumFee(): float
    {
        return self::MINIMUM_FEE;
    }

    public function getMaximumFee(): ?float
    {
        return self::MAXIMUM_FEE;
    }
}
