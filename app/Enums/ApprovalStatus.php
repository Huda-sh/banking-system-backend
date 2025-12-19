<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case ESCALATED = 'escalated';
    case DELEGATED = 'delegated';

    /**
     * Get the displayable label for the approval status.
     */
    public function getLabel(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
            self::ESCALATED => 'Escalated',
            self::DELEGATED => 'Delegated',
        };
    }

    /**
     * Get the color code for UI display.
     */
    public function getColor(): string
    {
        return match($this) {
            self::PENDING => 'warning',
            self::APPROVED => 'success',
            self::REJECTED, self::CANCELLED => 'danger',
            self::ESCALATED, self::DELEGATED => 'info',
        };
    }

    /**
     * Check if the status is final.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::APPROVED, self::REJECTED, self::CANCELLED]);
    }
}
