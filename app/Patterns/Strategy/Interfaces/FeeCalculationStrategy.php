<?php

namespace App\Patterns\Strategy\Interfaces;

use App\Models\Account;
use App\Models\Transaction;
use App\Exceptions\StrategyException;

interface FeeCalculationStrategy
{
    /**
     * Calculate the fee for a transaction.
     *
     * @param float $amount The transaction amount
     * @param Account $fromAccount The source account (if applicable)
     * @param Account $toAccount The destination account (if applicable)
     * @param array $context Additional context for fee calculation
     * @return float The calculated fee amount
     * @throws StrategyException If fee calculation fails
     */
    public function calculateFee(
        float $amount,
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): float;

    /**
     * Get the strategy name for logging and debugging.
     *
     * @return string The strategy name
     */
    public function getName(): string;

    /**
     * Get the strategy description.
     *
     * @return string The strategy description
     */
    public function getDescription(): string;

    /**
     * Get the fee breakdown details for transparency.
     *
     * @param float $amount The transaction amount
     * @param Account|null $fromAccount The source account
     * @param Account|null $toAccount The destination account
     * @param array $context Additional context
     * @return array Detailed breakdown of fee calculation
     */
    public function getFeeBreakdown(
        float $amount,
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): array;

    /**
     * Check if this strategy is applicable for the given transaction context.
     *
     * @param Account|null $fromAccount The source account
     * @param Account|null $toAccount The destination account
     * @param array $context Additional context
     * @return bool True if strategy is applicable, false otherwise
     */
    public function isApplicable(
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): bool;

    /**
     * Get the minimum fee amount for this strategy.
     *
     * @return float The minimum fee amount
     */
    public function getMinimumFee(): float;

    /**
     * Get the maximum fee amount for this strategy.
     *
     * @return float|null The maximum fee amount, or null if no maximum
     */
    public function getMaximumFee(): ?float;
}
