<?php

namespace App\Enums;

enum TransactionType: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
    case TRANSFER = 'transfer';
    case SCHEDULED = 'scheduled';
    case LOAN_PAYMENT = 'loan_payment';
    case INTEREST_PAYMENT = 'interest_payment';
    case FEE_CHARGE = 'fee_charge';
    case REVERSAL = 'reversal';
    case ADJUSTMENT = 'adjustment';

    /**
     * Get the displayable label for the transaction type.
     */
    public function getLabel(): string
    {
        return match($this) {
            self::DEPOSIT => 'Deposit',
            self::WITHDRAWAL => 'Withdrawal',
            self::TRANSFER => 'Transfer',
            self::SCHEDULED => 'Scheduled Transaction',
            self::LOAN_PAYMENT => 'Loan Payment',
            self::INTEREST_PAYMENT => 'Interest Payment',
            self::FEE_CHARGE => 'Fee Charge',
            self::REVERSAL => 'Reversal',
            self::ADJUSTMENT => 'Adjustment',
        };
    }

    /**
     * Get the description for the transaction type.
     */
    public function getDescription(): string
    {
        return match($this) {
            self::DEPOSIT => 'Funds added to account',
            self::WITHDRAWAL => 'Funds withdrawn from account',
            self::TRANSFER => 'Funds moved between accounts',
            self::SCHEDULED => 'Automatically recurring transaction',
            self::LOAN_PAYMENT => 'Payment towards loan principal and interest',
            self::INTEREST_PAYMENT => 'Interest earned or paid on account',
            self::FEE_CHARGE => 'Service fee or charge applied to account',
            self::REVERSAL => 'Reversal of a previous transaction',
            self::ADJUSTMENT => 'Manual balance adjustment by administrator',
        };
    }

    /**
     * Check if this transaction type affects account balance positively.
     */
    public function isCredit(): bool
    {
        return in_array($this, [self::DEPOSIT, self::INTEREST_PAYMENT, self::ADJUSTMENT]);
    }

    /**
     * Check if this transaction type affects account balance negatively.
     */
    public function isDebit(): bool
    {
        return in_array($this, [self::WITHDRAWAL, self::FEE_CHARGE]);
    }

    /**
     * Check if this transaction type involves movement between accounts.
     */
    public function isTransfer(): bool
    {
        return in_array($this, [self::TRANSFER, self::LOAN_PAYMENT]);
    }

    /**
     * Check if this transaction type is automated/recurring.
     */
    public function isAutomated(): bool
    {
        return in_array($this, [self::SCHEDULED, self::INTEREST_PAYMENT]);
    }

    /**
     * Get transaction types that require from_account.
     */
    public static function requiresFromAccount(): array
    {
        return [
            self::WITHDRAWAL->value,
            self::TRANSFER->value,
            self::LOAN_PAYMENT->value,
            self::FEE_CHARGE->value,
            self::REVERSAL->value
        ];
    }

    /**
     * Get transaction types that require to_account.
     */
    public static function requiresToAccount(): array
    {
        return [
            self::DEPOSIT->value,
            self::TRANSFER->value,
            self::LOAN_PAYMENT->value,
            self::INTEREST_PAYMENT->value,
            self::ADJUSTMENT->value
        ];
    }

    /**
     * Validate if a transaction type is valid for given account types.
     */
    public function isValidForAccountTypes(?string $fromAccountType = null, ?string $toAccountType = null): bool
    {
        // Basic validation - all types are generally valid
        if ($this === self::DEPOSIT) {
            return $toAccountType !== null;
        }

        if ($this === self::WITHDRAWAL) {
            return $fromAccountType !== null;
        }

        if ($this === self::TRANSFER) {
            return $fromAccountType !== null && $toAccountType !== null;
        }

        return true;
    }

    /**
     * Get all transaction types as an array.
     */
    public static function toArray(): array
    {
        return array_map(fn($case) => [
            'value' => $case->value,
            'label' => $case->getLabel(),
            'description' => $case->getDescription()
        ], self::cases());
    }

    /**
     * Get transaction type by value.
     */
    public static function fromValue(string $value): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }
        return null;
    }
}
