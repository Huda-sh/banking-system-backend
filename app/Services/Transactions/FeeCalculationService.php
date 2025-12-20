<?php

namespace App\Services\Transactions;

use App\Models\Account;
use App\Models\AccountType;
use App\Enums\TransactionType;
use App\Exceptions\FeeCalculationException;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FeeCalculationService
{
    /**
     * Default fee rates configuration.
     */
    const DEFAULT_FEE_RATES = [
        'domestic_transfer' => 0.01,    // 1% fee
        'international_transfer' => 0.03, // 3% fee
        'withdrawal' => 0.005,          // 0.5% fee
        'atm_withdrawal' => 2.50,       // $2.50 flat fee
        'wire_transfer' => 25.00,       // $25 flat fee
        'overdraft' => 35.00,           // $35 flat fee
        'monthly_maintenance' => 10.00, // $10 monthly fee
        'inactivity' => 5.00,           // $5 inactivity fee
    ];

    /**
     * Fee calculation strategies.
     */
    const FEE_STRATEGIES = [
        'percentage' => \App\Accounts\Strategies\PercentageFeeStrategy::class,
        'flat' => \App\Accounts\Strategies\FlatFeeStrategy::class,
        'tiered' => \App\Accounts\Strategies\TieredFeeStrategy::class,
        'waived' => \App\Accounts\Strategies\WaivedFeeStrategy::class,
        'dynamic' => \App\Accounts\Strategies\DynamicFeeStrategy::class,
    ];

    /**
     * Fee exemptions based on account features.
     */
    const FEE_EXEMPTIONS = [
        'no_domestic_transfer_fees' => ['domestic_transfer'],
        'no_international_transfer_fees' => ['international_transfer'],
        'no_withdrawal_fees' => ['withdrawal', 'atm_withdrawal'],
        'no_monthly_fees' => ['monthly_maintenance'],
        'premium_account' => ['all'] // All fees waived
    ];

    /**
     * FeeCalculationService constructor.
     */
    public function __construct() {}

    /**
     * Calculate fee for a transaction.
     *
     * @throws FeeCalculationException
     */
    public function calculateFee(
        TransactionType $transactionType,
        float $amount,
        ?int $fromAccountId = null,
        ?int $toAccountId = null,
        array $context = []
    ): float {
        try {
            $fromAccount = $fromAccountId ? Account::find($fromAccountId) : null;
            $toAccount = $toAccountId ? Account::find($toAccountId) : null;

            // Check if fee is waived
            if ($this->isFeeWaived($transactionType, $fromAccount, $toAccount)) {
                return 0.00;
            }

            // Get fee configuration
            $feeConfig = $this->getFeeConfiguration($transactionType, $fromAccount, $toAccount, $context);

            // Calculate base fee
            $baseFee = $this->calculateBaseFee($transactionType, $amount, $feeConfig);

            // Apply discounts
            $discountedFee = $this->applyDiscounts($baseFee, $fromAccount, $toAccount, $feeConfig);

            // Apply caps and minimums
            $finalFee = $this->applyFeeCapsAndMinimums($discountedFee, $feeConfig);

            Log::debug('Fee calculation completed', [
                'transaction_type' => $transactionType->value,
                'amount' => $amount,
                'base_fee' => $baseFee,
                'discounted_fee' => $discountedFee,
                'final_fee' => $finalFee,
                'from_account' => $fromAccountId,
                'to_account' => $toAccountId
            ]);

            return round($finalFee, 2);

        } catch (\Exception $e) {
            Log::error('Fee calculation failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'transaction_type' => $transactionType->value,
                'amount' => $amount
            ]);

            throw new FeeCalculationException('Failed to calculate fee: ' . $e->getMessage());
        }
    }

    /**
     * Check if fee is waived for this transaction.
     */
    private function isFeeWaived(
        TransactionType $transactionType,
        ?Account $fromAccount = null,
        ?Account $toAccount = null
    ): bool {
        // Check account-level exemptions
        if ($fromAccount && $this->isAccountExempt($fromAccount, $transactionType)) {
            return true;
        }

        if ($toAccount && $this->isAccountExempt($toAccount, $transactionType)) {
            return true;
        }

        // Check cross-account exemptions
        if ($fromAccount && $toAccount && $this->isCrossAccountExempt($fromAccount, $toAccount)) {
            return true;
        }

        // Check premium features
        if ($fromAccount && $fromAccount->hasFeature('premium_account')) {
            return true;
        }

        return false;
    }

    /**
     * Check if account has fee exemption for transaction type.
     */
    private function isAccountExempt(Account $account, TransactionType $transactionType): bool
    {
        $accountFeatures = $account->features->pluck('class_name')->toArray();

        foreach (self::FEE_EXEMPTIONS as $feature => $exemptTransactions) {
            if (in_array($feature, $accountFeatures)) {
                if (in_array('all', $exemptTransactions) || in_array($transactionType->value, $exemptTransactions)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if cross-account transactions are exempt.
     */
    private function isCrossAccountExempt(Account $fromAccount, Account $toAccount): bool
    {
        // Same customer accounts are exempt from transfer fees
        if ($fromAccount->users->pluck('id')->intersect($toAccount->users->pluck('id'))->isNotEmpty()) {
            return true;
        }

        // Same account group transactions are exempt
        if ($fromAccount->parent_account_id && $fromAccount->parent_account_id === $toAccount->parent_account_id) {
            return true;
        }

        return false;
    }

    /**
     * Get fee configuration for transaction type.
     */
    private function getFeeConfiguration(
        TransactionType $transactionType,
        ?Account $fromAccount = null,
        ?Account $toAccount = null,
        array $context = []
    ): array {
        // Get base fee rates from configuration
        $baseRates = config('banking.fee_rates', self::DEFAULT_FEE_RATES);

        // Get account-specific fee configuration
        $accountFeeConfig = $fromAccount ? $this->getAccountFeeConfiguration($fromAccount) : [];

        // Determine fee type and rate based on transaction type
        $feeConfig = match($transactionType) {
            TransactionType::TRANSFER => $this->getTransferFeeConfig($fromAccount, $toAccount, $context),
            TransactionType::WITHDRAWAL => $this->getWithdrawalFeeConfig($fromAccount, $context),
            TransactionType::DEPOSIT => $this->getDepositFeeConfig($fromAccount, $toAccount),
            TransactionType::INTERNATIONAL_TRANSFER => $this->getInternationalTransferFeeConfig($fromAccount, $toAccount),
            TransactionType::ATM_WITHDRAWAL => $this->getAtmWithdrawalFeeConfig($fromAccount),
            TransactionType::WIRE_TRANSFER => $this->getWireTransferFeeConfig($fromAccount, $toAccount),
            TransactionType::OVERDRAFT => $this->getOverdraftFeeConfig($fromAccount),
            default => [
                'type' => 'percentage',
                'rate' => $baseRates['domestic_transfer'] ?? 0.01,
                'minimum' => 0.50,
                'maximum' => 50.00,
                'strategy' => 'percentage'
            ]
        };

        // Merge with account-specific configuration
        return array_merge($feeConfig, $accountFeeConfig);
    }

    /**
     * Get transfer fee configuration.
     */
    private function getTransferFeeConfig(?Account $fromAccount, ?Account $toAccount, array $context): array
    {
        $isInternational = $this->isInternationalTransfer($fromAccount, $toAccount);

        return [
            'type' => $isInternational ? 'percentage' : 'percentage',
            'rate' => $isInternational ? 0.03 : 0.01, // 3% international, 1% domestic
            'minimum' => $isInternational ? 10.00 : 1.00,
            'maximum' => $isInternational ? 100.00 : 50.00,
            'strategy' => $isInternational ? 'percentage' : 'percentage',
            'is_international' => $isInternational
        ];
    }

    /**
     * Check if transfer is international.
     */
    private function isInternationalTransfer(?Account $fromAccount, ?Account $toAccount): bool
    {
        if (!$fromAccount || !$toAccount) {
            return false;
        }

        return $fromAccount->currency !== $toAccount->currency;
    }

    /**
     * Get withdrawal fee configuration.
     */
    private function getWithdrawalFeeConfig(?Account $fromAccount, array $context): array
    {
        $isAtm = $context['is_atm'] ?? false;

        return [
            'type' => $isAtm ? 'flat' : 'percentage',
            'rate' => $isAtm ? 2.50 : 0.005, // $2.50 ATM fee, 0.5% regular withdrawal
            'minimum' => $isAtm ? 2.50 : 0.50,
            'maximum' => $isAtm ? 2.50 : 25.00,
            'strategy' => $isAtm ? 'flat' : 'percentage',
            'is_atm' => $isAtm
        ];
    }

    /**
     * Get deposit fee configuration.
     */
    private function getDepositFeeConfig(?Account $fromAccount, ?Account $toAccount): array
    {
        // Most deposits are free, but some may have fees
        return [
            'type' => 'percentage',
            'rate' => 0.00, // No fee for deposits
            'minimum' => 0.00,
            'maximum' => 0.00,
            'strategy' => 'waived'
        ];
    }

    /**
     * Get international transfer fee configuration.
     */
    private function getInternationalTransferFeeConfig(?Account $fromAccount, ?Account $toAccount): array
    {
        return [
            'type' => 'percentage',
            'rate' => 0.03, // 3% fee
            'minimum' => 10.00,
            'maximum' => 100.00,
            'strategy' => 'percentage',
            'includes_exchange_rate_margin' => true
        ];
    }

    /**
     * Get ATM withdrawal fee configuration.
     */
    private function getAtmWithdrawalFeeConfig(?Account $fromAccount): array
    {
        return [
            'type' => 'flat',
            'rate' => 2.50, // $2.50 flat fee
            'minimum' => 2.50,
            'maximum' => 2.50,
            'strategy' => 'flat',
            'out_of_network' => true
        ];
    }

    /**
     * Get wire transfer fee configuration.
     */
    private function getWireTransferFeeConfig(?Account $fromAccount, ?Account $toAccount): array
    {
        return [
            'type' => 'flat',
            'rate' => 25.00, // $25 flat fee
            'minimum' => 25.00,
            'maximum' => 25.00,
            'strategy' => 'flat',
            'includes_correspondent_bank_fees' => true
        ];
    }

    /**
     * Get overdraft fee configuration.
     */
    private function getOverdraftFeeConfig(?Account $fromAccount): array
    {
        return [
            'type' => 'flat',
            'rate' => 35.00, // $35 flat fee
            'minimum' => 35.00,
            'maximum' => 35.00,
            'strategy' => 'flat',
            'per_item' => true
        ];
    }

    /**
     * Get account-specific fee configuration.
     */
    private function getAccountFeeConfiguration(Account $account): array
    {
        $config = [];

        // Account type specific fees
        if ($account->accountType) {
            $config = array_merge($config, $this->getAccountTypeFeeConfig($account->accountType));
        }

        // Premium account benefits
        if ($account->hasFeature('premium_account')) {
            $config = array_merge($config, [
                'rate' => $config['rate'] * 0.5, // 50% discount
                'minimum' => $config['minimum'] * 0.5,
                'maximum' => $config['maximum'] * 0.5
            ]);
        }

        // High balance discounts
        if ($account->balance > 100000) {
            $config = array_merge($config, [
                'rate' => $config['rate'] * 0.75, // 25% discount
                'minimum' => $config['minimum'] * 0.75
            ]);
        }

        return $config;
    }

    /**
     * Get account type fee configuration.
     */
    private function getAccountTypeFeeConfig(AccountType $accountType): array
    {
        return match($accountType->name) {
            'Premium Checking' => [
                'rate' => 0.005, // 0.5% fee
                'minimum' => 0.25,
                'maximum' => 25.00
            ],
            'Business Checking' => [
                'rate' => 0.015, // 1.5% fee
                'minimum' => 1.00,
                'maximum' => 75.00
            ],
            'Student Account' => [
                'rate' => 0.00, // No fees
                'minimum' => 0.00,
                'maximum' => 0.00
            ],
            default => [
                'rate' => 0.01, // 1% default fee
                'minimum' => 0.50,
                'maximum' => 50.00
            ]
        };
    }

    /**
     * Calculate base fee using appropriate strategy.
     */
    private function calculateBaseFee(TransactionType $transactionType, float $amount, array $feeConfig): float
    {
        $strategyClass = self::FEE_STRATEGIES[$feeConfig['strategy']] ?? self::FEE_STRATEGIES['percentage'];

        if (!class_exists($strategyClass)) {
            throw new FeeCalculationException("Fee strategy class not found: {$strategyClass}");
        }

        $strategy = app($strategyClass);

        return $strategy->calculate(
            $amount,
            $feeConfig['rate'],
            $feeConfig['minimum'] ?? 0,
            $feeConfig['maximum'] ?? null
        );
    }

    /**
     * Apply discounts to the fee.
     */
    private function applyDiscounts(float $baseFee, ?Account $fromAccount, ?Account $toAccount, array $feeConfig): float
    {
        $discountedFee = $baseFee;

        // Account relationship discounts
        if ($fromAccount && $toAccount && $this->isSameCustomer($fromAccount, $toAccount)) {
            $discountedFee *= 0.5; // 50% discount for same customer
        }

        // Volume discounts
        if ($fromAccount && $this->getMonthlyTransactionVolume($fromAccount) > 100) {
            $discountedFee *= 0.8; // 20% discount for high volume
        }

        // Loyalty discounts
        if ($fromAccount && $this->getAccountAgeInMonths($fromAccount) > 24) {
            $discountedFee *= 0.9; // 10% discount for loyal customers
        }

        // Promotional discounts
        if ($this->isPromotionalPeriod()) {
            $discountedFee *= 0.75; // 25% promotional discount
        }

        return $discountedFee;
    }

    /**
     * Check if accounts belong to the same customer.
     */
    private function isSameCustomer(Account $fromAccount, Account $toAccount): bool
    {
        return $fromAccount->users->pluck('id')->intersect($toAccount->users->pluck('id'))->isNotEmpty();
    }

    /**
     * Get monthly transaction volume for account.
     */
    private function getMonthlyTransactionVolume(Account $account): int
    {
        // In production, this would query the database
        // For now, return a default value
        return 50;
    }

    /**
     * Get account age in months.
     */
    private function getAccountAgeInMonths(Account $account): int
    {
        return $account->created_at->diffInMonths(now());
    }

    /**
     * Check if currently in promotional period.
     */
    private function isPromotionalPeriod(): bool
    {
        // Check if current date is within promotional period
        $promotionalPeriods = config('banking.promotional_periods', []);

        $today = now()->format('Y-m-d');

        foreach ($promotionalPeriods as $period) {
            if ($today >= $period['start_date'] && $today <= $period['end_date']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply fee caps and minimums.
     */
    private function applyFeeCapsAndMinimums(float $fee, array $feeConfig): float
    {
        $minimum = $feeConfig['minimum'] ?? 0;
        $maximum = $feeConfig['maximum'] ?? null;

        if ($fee < $minimum) {
            return $minimum;
        }

        if ($maximum !== null && $fee > $maximum) {
            return $maximum;
        }

        return $fee;
    }

    /**
     * Calculate monthly maintenance fee for account.
     */
    public function calculateMonthlyMaintenanceFee(Account $account): float
    {
        // Check if account is exempt
        if ($account->hasFeature('no_monthly_fees')) {
            return 0.00;
        }

        // Base maintenance fee
        $baseFee = self::DEFAULT_FEE_RATES['monthly_maintenance'] ?? 10.00;

        // Balance-based waivers
        if ($account->balance >= 15000) {
            return 0.00; // Waived for high balance
        }

        // Student account waiver
        if ($account->accountType?->name === 'Student Account') {
            return 0.00;
        }

        // Apply account type specific fees
        $accountTypeFee = $this->getAccountTypeMonthlyFee($account->accountType);

        if ($accountTypeFee !== null) {
            $baseFee = $accountTypeFee;
        }

        // Age-based discounts
        if ($this->getAccountAgeInMonths($account) > 12) {
            $baseFee *= 0.75; // 25% discount for accounts over 1 year old
        }

        return round($baseFee, 2);
    }

    /**
     * Get account type monthly fee.
     */
    private function getAccountTypeMonthlyFee(?AccountType $accountType): ?float
    {
        if (!$accountType) {
            return null;
        }

        return match($accountType->name) {
            'Premium Checking' => 25.00,
            'Business Checking' => 15.00,
            'Basic Checking' => 10.00,
            'Student Account' => 0.00,
            'Savings Account' => 5.00,
            default => null
        };
    }

    /**
     * Calculate inactivity fee for account.
     */
    public function calculateInactivityFee(Account $account): float
    {
        // Check if account is exempt
        if ($account->hasFeature('no_inactivity_fees')) {
            return 0.00;
        }

        // Check last transaction date
        $lastTransaction = $account->transactions()
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$lastTransaction || $lastTransaction->created_at->diffInMonths(now()) < 6) {
            return 0.00; // No fee if active within last 6 months
        }

        return self::DEFAULT_FEE_RATES['inactivity'] ?? 5.00;
    }

    /**
     * Calculate overdraft fee for account.
     */
    public function calculateOverdraftFee(Account $account, float $overdraftAmount): float
    {
        // Check if account has overdraft protection
        if ($account->hasFeature('overdraft_protection')) {
            return 0.00; // No fee with protection
        }

        // Base overdraft fee
        $baseFee = self::DEFAULT_FEE_RATES['overdraft'] ?? 35.00;

        // Multiple overdraft discount
        $recentOverdrafts = $account->transactions()
            ->where('type', 'overdraft')
            ->where('created_at', '>', now()->subMonth())
            ->count();

        if ($recentOverdrafts > 3) {
            $baseFee *= 0.5; // 50% discount for frequent overdrafts
        }

        return round($baseFee, 2);
    }

    /**
     * Get fee breakdown for transparency.
     */
    public function getFeeBreakdown(
        TransactionType $transactionType,
        float $amount,
        ?int $fromAccountId = null,
        ?int $toAccountId = null,
        array $context = []
    ): array {
        $fromAccount = $fromAccountId ? Account::find($fromAccountId) : null;
        $toAccount = $toAccountId ? Account::find($toAccountId) : null;

        $feeConfig = $this->getFeeConfiguration($transactionType, $fromAccount, $toAccount, $context);
        $baseFee = $this->calculateBaseFee($transactionType, $amount, $feeConfig);
        $discountedFee = $this->applyDiscounts($baseFee, $fromAccount, $toAccount, $feeConfig);
        $finalFee = $this->applyFeeCapsAndMinimums($discountedFee, $feeConfig);

        $breakdown = [
            'transaction_type' => $transactionType->getLabel(),
            'amount' => $amount,
            'base_fee' => $baseFee,
            'discounts' => [],
            'final_fee' => $finalFee,
            'waived' => $this->isFeeWaived($transactionType, $fromAccount, $toAccount)
        ];

        // Add discount details
        if ($baseFee > $discountedFee) {
            $breakdown['discounts'][] = [
                'type' => 'account_relationship',
                'amount' => $baseFee - $discountedFee,
                'description' => 'Discount for account relationships'
            ];
        }

        return $breakdown;
    }
}
