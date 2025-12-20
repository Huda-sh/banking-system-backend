<?php

namespace App\Enums;

enum ApprovalStatus: string
{// approval_status AS ENUM ('pending', 'approved', 'rejected');
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';


    /**
     * Get the displayable label for the approval status.
     */
    public function getLabel(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::APPROVED => 'Approved',
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
            self::APPROVED => 'success',
            self::REJECTED, => 'danger',
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
