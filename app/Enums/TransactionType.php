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
    case INTERNATIONAL_TRANSFER = 'international_transfer';
    case ATM_WITHDRAWAL = 'atm_withdrawal';
    case WIRE_TRANSFER = 'wire_transfer';
    case LARGE_CASH_WITHDRAWAL = 'large_cash_withdrawal';

    case OVERDRAFT ='over_draft';

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
            self::INTERNATIONAL_TRANSFER => 'International Transfer',
            self::ATM_WITHDRAWAL => 'ATM Withdrawal',
            self::WIRE_TRANSFER => 'Wire Transfer',
            self::LARGE_CASH_WITHDRAWAL => 'Large Cash Withdrawal',
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
            self::INTERNATIONAL_TRANSFER => 'Transfer between accounts in different currencies',
            self::ATM_WITHDRAWAL => 'Cash withdrawal from ATM',
            self::WIRE_TRANSFER => 'Electronic funds transfer via wire network',
            self::LARGE_CASH_WITHDRAWAL => 'Large cash withdrawal requiring additional verification',
        };
    }
}
