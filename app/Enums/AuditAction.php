<?php

namespace App\Enums;

enum AuditAction: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case DELETED = 'deleted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case REVERSED = 'reversed';
    case FAILED = 'failed';
    case SCHEDULED = 'scheduled';
    case EXECUTED = 'executed';
    case VIEWED = 'viewed';
    case EXPORTED = 'exported';
    case LOGIN = 'login';
    case LOGOUT = 'logout';
    case PASSWORD_CHANGE = 'password_change';
    case ROLE_CHANGE = 'role_change';
    case PROCESSING_STARTED = 'processing_started';
    case PROCESSING_COMPLETED = 'processing_completed';
    case PROCESSING_FAILED = 'processing_failed';

    /**
     * Get the displayable label for the audit action.
     */
    public function getLabel(): string
    {
        return match($this) {
            self::CREATED => 'Created',
            self::UPDATED => 'Updated',
            self::DELETED => 'Deleted',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
            self::REVERSED => 'Reversed',
            self::FAILED => 'Failed',
            self::SCHEDULED => 'Scheduled',
            self::EXECUTED => 'Executed',
            self::VIEWED => 'Viewed',
            self::EXPORTED => 'Exported',
            self::LOGIN => 'Login',
            self::LOGOUT => 'Logout',
            self::PASSWORD_CHANGE => 'Password Changed',
            self::ROLE_CHANGE => 'Role Changed',
            self::PROCESSING_STARTED => 'Processing Started',
            self::PROCESSING_COMPLETED => 'Processing Completed',
            self::PROCESSING_FAILED => 'Processing Failed',
        };
    }
}
