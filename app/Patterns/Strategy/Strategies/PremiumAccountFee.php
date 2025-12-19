<?php

namespace App\Patterns\Strategy\Strategies;

use App\Models\Account;
use App\Models\Transaction;
use App\Patterns\Strategy\Interfaces\FeeCalculationStrategy;
use App\Exceptions\StrategyException;
use Illuminate\Support\Facades\Log;

class PremiumAccountFee implements FeeCalculationStrategy
{
    /**
     * Reduced fee rate for premium accounts (0.5%).
     */
    const PREMIUM_FEE_RATE = 0.005;

    /**
     * Minimum fee amount for premium accounts.
     */
    const MINIMUM_FEE = 0.25;

    /**
     * Maximum fee amount for premium accounts.
     */
    const MAXIMUM_FEE = 25.00;

    /**
     * Priority service surcharge (optional).
     */
    const PRIORITY_SURCHARGE = 5.00;

    public function calculateFee(
        float $amount,
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): float {
        try {
            Log::debug('PremiumAccountFee: Calculating fee for premium account', [
                'amount' => $amount,
                'from_account_id' => $fromAccount?->id,
                'to_account_id' => $toAccount?->id
            ]);

            // Calculate base fee with premium rate
            $baseFee = $amount * self::PREMIUM_FEE_RATE;

            // Apply priority service surcharge if applicable
            $prioritySurcharge = $this->getPrioritySurcharge($context);
            $totalFee = $baseFee + $prioritySurcharge;

            // Apply premium account specific discounts
            $discountedFee = $this->applyPremiumDiscounts($totalFee, $fromAccount, $toAccount, $context);

            // Apply minimum and maximum fee constraints
            $finalFee = $this->applyFeeConstraints($discountedFee);

            Log::debug('PremiumAccountFee: Fee calculation completed', [
                'base_fee' => $baseFee,
                'priority_surcharge' => $prioritySurcharge,
                'discounted_fee' => $discountedFee,
                'final_fee' => $finalFee
            ]);

            return $finalFee;

        } catch (\Exception $e) {
            Log::error('PremiumAccountFee: Fee calculation failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'amount' => $amount
            ]);
            throw new StrategyException("Premium account fee calculation failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function getPrioritySurcharge(array $context): float
    {
        // Apply priority surcharge for urgent transfers
        if (isset($context['priority']) && $context['priority'] === 'urgent') {
            Log::debug('PremiumAccountFee: Priority surcharge applied', [
                'surcharge_amount' => self::PRIORITY_SURCHARGE
            ]);
            return self::PRIORITY_SURCHARGE;
        }

        return 0.0;
    }

    private function applyPremiumDiscounts(float $baseFee, ?Account $fromAccount, ?Account $toAccount, array $context): float
    {
        $discountedFee = $baseFee;

        // Loyalty discount for long-term premium customers
        if ($fromAccount && $this->isLoyalCustomer($fromAccount)) {
            $discountedFee *= 0.9; // 10% loyalty discount
            Log::debug('PremiumAccountFee: Loyalty discount applied', [
                'original_fee' => $baseFee,
                'discounted_fee' => $discountedFee
            ]);
        }

        // Volume discount for high transaction volumes
        if ($fromAccount && $this->isHighVolumeCustomer($fromAccount)) {
            $discountedFee *= 0.85; // 15% volume discount
            Log::debug('PremiumAccountFee: Volume discount applied', [
                'original_fee' => $baseFee,
                'discounted_fee' => $discountedFee
            ]);
        }

        // Waive minimum fee for very large transactions
        if ($baseFee > 100 && $discountedFee < self::MINIMUM_FEE) {
            Log::debug('PremiumAccountFee: Minimum fee waived for large transaction', [
                'transaction_amount' => $context['amount'] ?? 0,
                'calculated_fee' => $discountedFee
            ]);
            return $discountedFee;
        }

        return $discountedFee;
    }

    private function isLoyalCustomer(Account $account): bool
    {
        return $account->created_at->diffInMonths(now()) >= 24; // 2+ years
    }

    private function isHighVolumeCustomer(Account $account): bool
    {
        // In production, this would check actual transaction volumes
        // For now, use a simple heuristic
        return $account->balance > 100000;
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
        return 'PremiumAccountFee';
    }

    public function getDescription(): string
    {
        return 'Reduced fee strategy for premium accounts with priority service options and loyalty discounts';
    }

    public function getFeeBreakdown(
        float $amount,
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): array {
        $baseFee = $amount * self::PREMIUM_FEE_RATE;
        $prioritySurcharge = $this->getPrioritySurcharge($context);
        $totalFee = $baseFee + $prioritySurcharge;
        $discountedFee = $this->applyPremiumDiscounts($totalFee, $fromAccount, $toAccount, $context);
        $finalFee = $this->applyFeeConstraints($discountedFee);

        $breakdown = [
            'strategy' => $this->getName(),
            'premium_rate' => self::PREMIUM_FEE_RATE,
            'base_fee' => $baseFee,
            'priority_surcharge' => $prioritySurcharge,
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
                'type' => 'premium_discounts',
                'amount' => $discountAmount,
                'percentage' => ($discountAmount / $totalFee) * 100
            ];
        }

        // Add loyalty discount details
        if ($fromAccount && $this->isLoyalCustomer($fromAccount)) {
            $breakdown['loyalty_discount'] = [
                'applied' => true,
                'months_as_customer' => $fromAccount->created_at->diffInMonths(now()),
                'discount_rate' => 0.10
            ];
        }

        return $breakdown;
    }

    public function isApplicable(
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): bool {
        // This strategy is applicable for premium accounts
        if ($fromAccount && $fromAccount->hasFeature('premium_account')) {
            return true;
        }

        // Also applicable if explicitly specified in context
        if (isset($context['account_type']) && $context['account_type'] === 'premium') {
            return true;
        }

        return false;
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
