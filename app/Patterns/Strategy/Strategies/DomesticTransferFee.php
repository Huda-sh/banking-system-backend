<?php

namespace App\Patterns\Strategy\Strategies;

use App\Models\Account;
use App\Patterns\Strategy\Interfaces\FeeCalculationStrategy;
use App\Exceptions\StrategyException;
use Illuminate\Support\Facades\Log;

class DomesticTransferFee implements FeeCalculationStrategy
{
    /**
     * Base fee rate for domestic transfers (1%).
     */
    const BASE_FEE_RATE = 0.01;

    /**
     * Minimum fee amount for domestic transfers.
     */
    const MINIMUM_FEE = 0.50;

    /**
     * Maximum fee amount for domestic transfers.
     */
    const MAXIMUM_FEE = 50.00;

    /**
     * Fee tiers for domestic transfers based on amount.
     */
    const FEE_TIERS = [
        ['min' => 0, 'max' => 1000, 'rate' => 0.015],   // 1.5% for amounts under $1,000
        ['min' => 1000, 'max' => 10000, 'rate' => 0.01], // 1% for amounts between $1,000 and $10,000
        ['min' => 10000, 'max' => null, 'rate' => 0.0075] // 0.75% for amounts over $10,000
    ];

    public function calculateFee(
        float $amount,
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): float {
        try {
            Log::debug('DomesticTransferFee: Calculating fee for domestic transfer', [
                'amount' => $amount,
                'from_account_id' => $fromAccount?->id,
                'to_account_id' => $toAccount?->id
            ]);

            // Get the applicable fee rate based on amount tiers
            $feeRate = $this->getApplicableFeeRate($amount);

            // Calculate base fee
            $baseFee = $amount * $feeRate;

            // Apply account-specific discounts
            $discountedFee = $this->applyAccountDiscounts($baseFee, $fromAccount, $toAccount, $context);

            // Apply minimum and maximum fee constraints
            $finalFee = $this->applyFeeConstraints($discountedFee);

            Log::debug('DomesticTransferFee: Fee calculation completed', [
                'base_fee' => $baseFee,
                'discounted_fee' => $discountedFee,
                'final_fee' => $finalFee,
                'fee_rate' => $feeRate
            ]);

            return $finalFee;

        } catch (\Exception $e) {
            Log::error('DomesticTransferFee: Fee calculation failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'amount' => $amount
            ]);
            throw new StrategyException("Domestic transfer fee calculation failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function getApplicableFeeRate(float $amount): float
    {
        foreach (self::FEE_TIERS as $tier) {
            if (($tier['min'] === 0 || $amount >= $tier['min']) &&
                ($tier['max'] === null || $amount < $tier['max'])) {
                return $tier['rate'];
            }
        }

        // Default to base rate if no tier matches
        return self::BASE_FEE_RATE;
    }

    private function applyAccountDiscounts(float $baseFee, ?Account $fromAccount, ?Account $toAccount, array $context): float
    {
        $discountedFee = $baseFee;

        // Premium account discount
        if ($fromAccount && $fromAccount->hasFeature('premium_account')) {
            $discountedFee *= 0.5; // 50% discount for premium accounts
            Log::debug('DomesticTransferFee: Premium account discount applied', [
                'original_fee' => $baseFee,
                'discounted_fee' => $discountedFee
            ]);
        }

        // Same customer discount (accounts belonging to the same user)
        if ($fromAccount && $toAccount && $this->isSameCustomer($fromAccount, $toAccount)) {
            $discountedFee *= 0.75; // 25% discount for same customer transfers
            Log::debug('DomesticTransferFee: Same customer discount applied', [
                'original_fee' => $baseFee,
                'discounted_fee' => $discountedFee
            ]);
        }

        // High volume customer discount
        if ($fromAccount && $this->isHighVolumeCustomer($fromAccount)) {
            $discountedFee *= 0.9; // 10% discount for high volume customers
        }

        return $discountedFee;
    }

    private function isSameCustomer(Account $fromAccount, Account $toAccount): bool
    {
        return $fromAccount->users->pluck('id')->intersect($toAccount->users->pluck('id'))->isNotEmpty();
    }

    private function isHighVolumeCustomer(Account $account): bool
    {
        // In production, this would check transaction history
        // For now, we'll use a simple check based on account age and balance
        return $account->created_at->diffInMonths(now()) > 12 && $account->balance > 50000;
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
        return 'DomesticTransferFee';
    }

    public function getDescription(): string
    {
        return 'Fee calculation strategy for domestic transfers between accounts in the same currency';
    }

    public function getFeeBreakdown(
        float $amount,
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): array {
        $baseRate = $this->getApplicableFeeRate($amount);
        $baseFee = $amount * $baseRate;
        $discountedFee = $this->applyAccountDiscounts($baseFee, $fromAccount, $toAccount, $context);
        $finalFee = $this->applyFeeConstraints($discountedFee);

        $breakdown = [
            'strategy' => $this->getName(),
            'base_rate' => $baseRate,
            'base_fee' => $baseFee,
            'discounts' => [],
            'minimum_fee' => self::MINIMUM_FEE,
            'maximum_fee' => self::MAXIMUM_FEE,
            'final_fee' => $finalFee
        ];

        // Add discount details if applicable
        if ($baseFee > $discountedFee) {
            $discountAmount = $baseFee - $discountedFee;
            $breakdown['discounts'][] = [
                'type' => 'account_discounts',
                'amount' => $discountAmount,
                'percentage' => ($discountAmount / $baseFee) * 100
            ];
        }

        return $breakdown;
    }

    public function isApplicable(
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): bool {
        // Check if this is a domestic transfer (same currency)
        if ($fromAccount && $toAccount) {
            return $fromAccount->currency === $toAccount->currency;
        }

        // Check context for transfer type
        return ($context['transfer_type'] ?? 'domestic') === 'domestic';
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
