<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case PENDING = 'pending';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REVERSED = 'reversed';
    case ON_HOLD = 'on_hold';
    case REJECTED = 'rejected';
    case SCHEDULED = 'scheduled';

    /**
     * Get the displayable label for the transaction status.
     */
    public function getLabel(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::PENDING_APPROVAL => 'Pending Approval',
            self::APPROVED => 'Approved',
            self::PROCESSING => 'Processing',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
            self::REVERSED => 'Reversed',
            self::ON_HOLD => 'On Hold',
            self::REJECTED => 'Rejected',
        };
    }

    /**
     * Get the color code for UI display.
     */
    public function getColor(): string
    {
        return match($this) {
            self::PENDING, self::PENDING_APPROVAL, self::APPROVED, self::PROCESSING, self::ON_HOLD => 'warning',
            self::COMPLETED => 'success',
            self::FAILED, self::CANCELLED, self::REVERSED, self::REJECTED => 'danger',
        };
    }

    /**
     * Check if the status is final (cannot be changed further).
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED, self::REVERSED]);
    }

    /**
     * Check if the status requires approval.
     */
    public function requiresApproval(): bool
    {
        return in_array($this, [self::PENDING_APPROVAL, self::ON_HOLD]);
    }
}
