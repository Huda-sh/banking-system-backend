<?php

namespace App\Enums;

enum AccountState: string
{
    case ACTIVE = 'active';
    case FROZEN = 'frozen';
    case SUSPENDED = 'suspended';
    case CLOSED = 'closed';

    /**
     * Get the displayable label for the account state.
     */
    public function getLabel(): string
    {
        return match($this) {
            self::ACTIVE => 'Active',
            self::FROZEN => 'Frozen',
            self::SUSPENDED => 'Suspended',
            self::CLOSED => 'Closed',
        };
    }

    /**
     * Get the description for the account state.
     */
    public function getDescription(): string
    {
        return match($this) {
            self::ACTIVE => 'Account is active and can perform all operations',
            self::FROZEN => 'Account is frozen - withdrawals and transfers are disabled',
            self::SUSPENDED => 'Account is suspended - only balance inquiries are allowed',
            self::CLOSED => 'Account is closed - no operations are allowed',
        };
    }

    /**
     * Check if the account can receive deposits in this state.
     */
    public function canReceiveDeposits(): bool
    {
        return in_array($this, [self::ACTIVE, self::FROZEN, self::SUSPENDED]);
    }

    /**
     * Check if the account can perform withdrawals in this state.
     */
    public function canWithdraw(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if the account can send transfers in this state.
     */
    public function canTransferFrom(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if the account can receive transfers in this state.
     */
    public function canTransferTo(): bool
    {
        return in_array($this, [self::ACTIVE, self::FROZEN]);
    }

    /**
     * Get the color code for UI display.
     */
    public function getColor(): string
    {
        return match($this) {
            self::ACTIVE => 'success',
            self::FROZEN => 'warning',
            self::SUSPENDED => 'danger',
            self::CLOSED => 'secondary',
        };
    }
}
