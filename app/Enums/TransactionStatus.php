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
     * Get the description for the transaction status.
     */
    public function getDescription(): string
    {
        return match($this) {
            self::PENDING => 'Transaction has been initiated but not yet processed',
            self::PENDING_APPROVAL => 'Transaction is awaiting approval from authorized personnel',
            self::APPROVED => 'Transaction has been approved and is ready for processing',
            self::PROCESSING => 'Transaction is currently being processed by the system',
            self::COMPLETED => 'Transaction has been successfully completed',
            self::FAILED => 'Transaction failed during processing',
            self::CANCELLED => 'Transaction was cancelled by the user or system',
            self::REVERSED => 'Transaction was reversed after completion',
            self::ON_HOLD => 'Transaction is temporarily on hold for review',
            self::REJECTED => 'Transaction was rejected during approval process',
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

    /**
     * Check if the status indicates success.
     */
    public function isSuccess(): bool
    {
        return in_array($this, [self::COMPLETED, self::APPROVED]);
    }

    /**
     * Check if the status indicates failure.
     */
    public function isFailure(): bool
    {
        return in_array($this, [self::FAILED, self::CANCELLED, self::REJECTED]);
    }

    /**
     * Check if the status can be modified.
     */
    public function canBeModified(): bool
    {
        return !$this->isFinal() && $this !== self::PROCESSING;
    }

    /**
     * Check if the status can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::PENDING, self::PENDING_APPROVAL, self::APPROVED, self::ON_HOLD]);
    }

    /**
     * Check if the status can be reversed.
     */
    public function canBeReversed(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * Get next possible statuses from current status.
     */
    public function getNextPossibleStatuses(): array
    {
        return match($this) {
            self::PENDING => [self::PENDING_APPROVAL, self::PROCESSING, self::CANCELLED, self::FAILED],
            self::PENDING_APPROVAL => [self::APPROVED, self::REJECTED, self::CANCELLED],
            self::APPROVED => [self::PROCESSING, self::CANCELLED],
            self::PROCESSING => [self::COMPLETED, self::FAILED],
            self::COMPLETED => [self::REVERSED],
            self::FAILED => [self::PENDING, self::CANCELLED],
            self::CANCELLED => [],
            self::REVERSED => [],
            self::ON_HOLD => [self::PENDING_APPROVAL, self::APPROVED, self::REJECTED, self::CANCELLED],
            self::REJECTED => [self::PENDING, self::CANCELLED],
        };
    }

    /**
     * Validate if a status transition is allowed.
     */
    public function canTransitionTo(self $newStatus): bool
    {
        return in_array($newStatus, $this->getNextPossibleStatuses());
    }

    /**
     * Get all transaction statuses as an array.
     */
    public static function toArray(): array
    {
        return array_map(fn($case) => [
            'value' => $case->value,
            'label' => $case->getLabel(),
            'description' => $case->getDescription(),
            'color' => $case->getColor()
        ], self::cases());
    }

    /**
     * Get transaction status by value.
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

    /**
     * Get statuses that require approval workflow.
     */
    public static function approvalRequiredStatuses(): array
    {
        return [self::PENDING_APPROVAL->value, self::ON_HOLD->value];
    }

    /**
     * Get statuses that indicate transaction completion.
     */
    public static function completedStatuses(): array
    {
        return [self::COMPLETED->value, self::REVERSED->value];
    }
}
