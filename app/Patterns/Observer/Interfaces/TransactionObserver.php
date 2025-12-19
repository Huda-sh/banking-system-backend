<?php

namespace App\Patterns\Observer\Interfaces;

use App\Models\Transaction;
use App\Models\Account;
use App\Exceptions\ObserverException;

interface TransactionObserver
{
    /**
     * Handle transaction creation event.
     */
    public function onTransactionCreated(Transaction $transaction): void;

    /**
     * Handle transaction completion event.
     */
    public function onTransactionCompleted(Transaction $transaction): void;

    /**
     * Handle transaction failure event.
     */
    public function onTransactionFailed(Transaction $transaction): void;

    /**
     * Handle transaction approval event.
     */
    public function onTransactionApproved(Transaction $transaction): void;

    /**
     * Handle transaction reversal event.
     */
    public function onTransactionReversed(Transaction $transaction): void;

    /**
     * Handle scheduled transaction execution event.
     */
    public function onScheduledTransactionExecuted(Transaction $transaction): void;

    /**
     * Get observer name for logging and debugging.
     */
    public function getName(): string;

    /**
     * Check if observer is enabled for current environment.
     */
    public function isEnabled(): bool;

    /**
     * Set observer priority (lower number = higher priority).
     */
    public function setPriority(int $priority): void;

    /**
     * Get observer priority.
     */
    public function getPriority(): int;
}
