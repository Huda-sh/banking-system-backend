<?php

namespace App\Enums;

enum TransactionType: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
    case TRANSFER = 'transfer';

    /**
     * Get the displayable label for the transaction type.
     */
    public function getLabel(): string
    {
        return match($this) {
            self::DEPOSIT => 'Deposit',
            self::WITHDRAWAL => 'Withdrawal',
            self::TRANSFER => 'Transfer',
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
        };
    }
}
