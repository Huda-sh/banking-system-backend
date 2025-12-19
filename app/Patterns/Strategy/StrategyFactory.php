<?php

namespace App\Patterns\Strategy;

use App\Models\Account;
use App\Models\Transaction;
use App\Patterns\Strategy\Interfaces\FeeCalculationStrategy;
use App\Exceptions\StrategyException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StrategyFactory
{
    /**
     * Strategy priority order (lower number = higher priority).
     */
    const STRATEGY_PRIORITY = [
        \App\Patterns\Strategy\Strategies\NoFeeStrategy::class => 10,
        \App\Patterns\Strategy\Strategies\PremiumAccountFee::class => 20,
        \App\Patterns\Strategy\Strategies\DomesticTransferFee::class => 30,
        \App\Patterns\Strategy\Strategies\InternationalTransferFee::class => 40
    ];

    /**
     * Cache for instantiated strategies.
     */
    private array $strategyCache = [];

    /**
     * Create and return the appropriate fee calculation strategy.
     *
     * @param Account|null $fromAccount The source account
     * @param Account|null $toAccount The destination account
     * @param array $context Additional context for strategy selection
     * @return FeeCalculationStrategy The selected strategy
     * @throws StrategyException If no applicable strategy is found
     */
    public function createStrategy(
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): FeeCalculationStrategy {
        try {
            Log::debug('StrategyFactory: Creating fee calculation strategy', [
                'from_account_id' => $fromAccount?->id,
                'to_account_id' => $toAccount?->id,
                'context' => $context
            ]);

            // Get all applicable strategies
            $applicableStrategies = $this->getApplicableStrategies($fromAccount, $toAccount, $context);

            if (empty($applicableStrategies)) {
                throw new StrategyException('No applicable fee calculation strategy found for the given context');
            }

            // Sort strategies by priority
            usort($applicableStrategies, function ($a, $b) {
                $priorityA = self::STRATEGY_PRIORITY[get_class($a)] ?? 999;
                $priorityB = self::STRATEGY_PRIORITY[get_class($b)] ?? 999;
                return $priorityA <=> $priorityB;
            });

            // Return the highest priority strategy
            $selectedStrategy = $applicableStrategies[0];
            $strategyName = get_class($selectedStrategy);

            Log::info('StrategyFactory: Strategy selected successfully', [
                'selected_strategy' => $strategyName,
                'applicable_strategies' => array_map('get_class', $applicableStrategies)
            ]);

            return $selectedStrategy;

        } catch (\Exception $e) {
            Log::error('StrategyFactory: Strategy creation failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'from_account_id' => $fromAccount?->id,
                'to_account_id' => $toAccount?->id
            ]);
            throw new StrategyException("Failed to create fee calculation strategy: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get all applicable strategies for the given context.
     */
    private function getApplicableStrategies(
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): array {
        $strategies = [];

        // Try to get strategies from cache first
        $cacheKey = $this->getCacheKey($fromAccount, $toAccount, $context);
        if (isset($this->strategyCache[$cacheKey])) {
            return $this->strategyCache[$cacheKey];
        }

        // Check each strategy for applicability
        foreach (array_keys(self::STRATEGY_PRIORITY) as $strategyClass) {
            if (!class_exists($strategyClass)) {
                Log::warning('StrategyFactory: Strategy class not found', [
                    'class' => $strategyClass
                ]);
                continue;
            }

            try {
                $strategy = $this->instantiateStrategy($strategyClass);
                if ($strategy->isApplicable($fromAccount, $toAccount, $context)) {
                    $strategies[] = $strategy;
                }
            } catch (\Exception $e) {
                Log::error('StrategyFactory: Strategy instantiation failed', [
                    'class' => $strategyClass,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e)
                ]);
                continue;
            }
        }

        // Cache the result
        $this->strategyCache[$cacheKey] = $strategies;

        return $strategies;
    }

    /**
     * Instantiate a strategy class with proper error handling.
     */
    private function instantiateStrategy(string $class): FeeCalculationStrategy
    {
        try {
            $strategy = app($class);

            if (!$strategy instanceof FeeCalculationStrategy) {
                throw new \RuntimeException("Class {$class} does not implement FeeCalculationStrategy interface");
            }

            return $strategy;

        } catch (\Exception $e) {
            Log::error('StrategyFactory: Strategy instantiation failed', [
                'class' => $class,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new StrategyException("Failed to instantiate strategy {$class}: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Generate a cache key for strategy lookup.
     */
    private function getCacheKey(
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): string {
        $keyParts = [
            $fromAccount?->id ?? 'null',
            $toAccount?->id ?? 'null',
            $fromAccount?->currency ?? 'null',
            $toAccount?->currency ?? 'null',
            $context['transfer_type'] ?? 'unknown',
            $context['account_type'] ?? 'unknown',
            isset($context['promotional']) ? ($context['promotional'] ? 'promo' : 'normal') : 'normal'
        ];

        return md5(implode(':', $keyParts));
    }

    /**
     * Create strategy for a specific transaction.
     */
    public function createStrategyForTransaction(Transaction $transaction): FeeCalculationStrategy
    {
        $fromAccount = $transaction->from_account_id ? Account::find($transaction->from_account_id) : null;
        $toAccount = $transaction->to_account_id ? Account::find($transaction->to_account_id) : null;

        $context = [
            'transaction_type' => $transaction->type->value,
            'transfer_type' => $this->determineTransferType($fromAccount, $toAccount),
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'priority' => $transaction->metadata['priority'] ?? null,
            'promotional' => $transaction->metadata['promotional'] ?? false,
            'transaction' => $transaction
        ];

        return $this->createStrategy($fromAccount, $toAccount, $context);
    }

    /**
     * Determine transfer type based on accounts.
     */
    private function determineTransferType(?Account $fromAccount, ?Account $toAccount): string
    {
        if (!$fromAccount || !$toAccount) {
            return 'unknown';
        }

        return $fromAccount->currency === $toAccount->currency ? 'domestic' : 'international';
    }

    /**
     * Get all available strategies.
     */
    public function getAllStrategies(): array
    {
        $strategies = [];

        foreach (array_keys(self::STRATEGY_PRIORITY) as $strategyClass) {
            if (class_exists($strategyClass)) {
                $strategy = $this->instantiateStrategy($strategyClass);
                $strategies[] = [
                    'class' => $strategyClass,
                    'name' => $strategy->getName(),
                    'description' => $strategy->getDescription(),
                    'priority' => self::STRATEGY_PRIORITY[$strategyClass] ?? 999
                ];
            }
        }

        return $strategies;
    }

    /**
     * Get strategy statistics for monitoring.
     */
    public function getStrategyStatistics(): array
    {
        $stats = [
            'total_strategies' => count(self::STRATEGY_PRIORITY),
            'strategy_classes' => array_keys(self::STRATEGY_PRIORITY),
            'cache_hits' => 0,
            'cache_misses' => 0,
            'instantiation_errors' => 0
        ];

        // This would be populated with actual metrics in production
        return $stats;
    }

    /**
     * Validate strategy configuration.
     */
    public function validateConfiguration(): bool
    {
        try {
            foreach (array_keys(self::STRATEGY_PRIORITY) as $strategyClass) {
                if (!class_exists($strategyClass)) {
                    Log::error('StrategyFactory: Configuration validation failed - class not found', [
                        'class' => $strategyClass
                    ]);
                    return false;
                }

                $strategy = $this->instantiateStrategy($strategyClass);
                if (!$strategy instanceof FeeCalculationStrategy) {
                    Log::error('StrategyFactory: Configuration validation failed - invalid interface', [
                        'class' => $strategyClass
                    ]);
                    return false;
                }
            }

            Log::info('StrategyFactory: Configuration validation successful');
            return true;

        } catch (\Exception $e) {
            Log::error('StrategyFactory: Configuration validation failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            return false;
        }
    }

    /**
     * Clear the strategy cache.
     */
    public function clearCache(): void
    {
        $this->strategyCache = [];
        Log::debug('StrategyFactory: Strategy cache cleared');
    }

    /**
     * Register a custom strategy.
     */
    public function registerCustomStrategy(string $strategyClass, int $priority = 100): void
    {
        if (!class_exists($strategyClass)) {
            throw new StrategyException("Strategy class not found: {$strategyClass}");
        }

        $strategy = $this->instantiateStrategy($strategyClass);
        if (!$strategy instanceof FeeCalculationStrategy) {
            throw new StrategyException("Class {$strategyClass} does not implement FeeCalculationStrategy interface");
        }

        self::STRATEGY_PRIORITY[$strategyClass] = $priority;
        Log::info('StrategyFactory: Custom strategy registered', [
            'class' => $strategyClass,
            'priority' => $priority
        ]);
    }
}
