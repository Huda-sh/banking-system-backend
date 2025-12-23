<?php

namespace App\Enums;

enum TicketStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case RESOLVED = 'resolved';

    /**
     * Get the displayable label for the ticket status.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::IN_PROGRESS => 'In Progress',
            self::RESOLVED => 'Resolved',
        };
    }

    /**
     * Get the color code for UI display.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::IN_PROGRESS => 'info',
            self::RESOLVED => 'success',
        };
    }

    /**
     * Check if the status is final (cannot be changed further).
     */
    public function isFinal(): bool
    {
        return $this === self::RESOLVED;
    }
}
