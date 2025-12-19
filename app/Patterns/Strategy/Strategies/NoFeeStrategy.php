<?php

namespace App\Patterns\Strategy\Strategies;

use App\Models\Account;
use App\Models\Transaction;
use App\Patterns\Strategy\Interfaces\FeeCalculationStrategy;
use App\Exceptions\StrategyException;
use Illuminate\Support\Facades\Log;

class NoFeeStrategy implements FeeCalculationStrategy
{
    public function calculateFee(
        float $amount,
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): float {
        try {
            Log::debug('NoFeeStrategy: No fee applied for transaction', [
                'amount' => $amount,
                'from_account_id' => $fromAccount?->id,
                'to_account_id' => $toAccount?->id,
                'reason' => $context['reason'] ?? 'No fee strategy applied'
            ]);

            return 0.0;

        } catch (\Exception $e) {
            Log::error('NoFeeStrategy: Fee calculation failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'amount' => $amount
            ]);
            throw new StrategyException("No fee strategy calculation failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getName(): string
    {
        return 'NoFeeStrategy';
    }

    public function getDescription(): string
    {
        return 'No fee strategy - applies zero fees for specific transaction types or account types';
    }

    public function getFeeBreakdown(
        float $amount,
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): array {
        return [
            'strategy' => $this->getName(),
            'base_fee' => 0.0,
            'discounts' => [],
            'minimum_fee' => 0.0,
            'maximum_fee' => 0.0,
            'final_fee' => 0.0,
            'reason' => $context['reason'] ?? 'No fee applied'
        ];
    }

    public function isApplicable(
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): bool {
        // This strategy is applicable for:
        // 1. Internal transfers between same customer accounts
        // 2. Specific account types (e.g., employee accounts)
        // 3. Promotional periods
        // 4. System-generated transactions

        // Check for same customer accounts
        if ($fromAccount && $toAccount && $this->isSameCustomer($fromAccount, $toAccount)) {
            return true;
        }

        // Check for employee accounts
        if ($fromAccount && $this->isEmployeeAccount($fromAccount)) {
            return true;
        }

        // Check for promotional context
        if (isset($context['promotional']) && $context['promotional'] === true) {
            return true;
        }

        // Check for system-generated transactions
        if (isset($context['system_generated']) && $context['system_generated'] === true) {
            return true;
        }

        return false;
    }

    private function isSameCustomer(Account $fromAccount, Account $toAccount): bool
    {
        return $fromAccount->users->pluck('id')->intersect($toAccount->users->pluck('id'))->isNotEmpty();
    }

    private function isEmployeeAccount(Account $account): bool
    {
        return $account->users()->where('is_employee', true)->exists();
    }

    public function getMinimumFee(): float
    {
        return 0.0;
    }

    public function getMaximumFee(): ?float
    {
        return 0.0;
    }
}
