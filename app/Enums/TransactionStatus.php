<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';

    /**
     * Get the displayable label for the transaction status.
     */
    public function getLabel(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::APPROVED => 'Approved',
            self::COMPLETED => 'Completed',
            self::REJECTED => 'Rejected',
        };
    }

    /**
     * Get the color code for UI display.
     */
    public function getColor(): string
    {
        return match($this) {
            self::PENDING => 'warning',
            self::APPROVED, self::COMPLETED => 'success',
            self::REJECTED => 'danger',
        };
    }

    /**
     * Check if the status is final (cannot be changed further).
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::REJECTED]);
    }

    /**
     * Check if the status requires approval.
     */
    public function requiresApproval(): bool
    {
        return in_array($this, [self::PENDING]);
    }
}
