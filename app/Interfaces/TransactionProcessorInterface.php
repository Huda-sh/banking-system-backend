<?php

namespace App\Interfaces;

use App\Models\Transaction;
use App\Exceptions\TransactionException;
use App\DTOs\TransactionData;

interface TransactionProcessorInterface
{
    /**
     * Process a transaction with all validations and business logic.
     *
     * @param TransactionData $transactionData The transaction data to process
     * @return Transaction The processed transaction
     * @throws TransactionException If processing fails
     */
    public function process(TransactionData $transactionData): Transaction;

    /**
     * Validate transaction data before processing.
     *
     * @param TransactionData $transactionData The transaction data to validate
     * @return bool True if validation passes
     * @throws TransactionException If validation fails
     */
    public function validate(TransactionData $transactionData): bool;

    /**
     * Reverse a completed transaction.
     *
     * @param Transaction $transaction The transaction to reverse
     * @param string|null $reason The reason for reversal
     * @return Transaction The reversed transaction
     * @throws TransactionException If reversal fails
     */
    public function reverse(Transaction $transaction, ?string $reason = null): Transaction;

    /**
     * Cancel a pending transaction.
     *
     * @param Transaction $transaction The transaction to cancel
     * @param string|null $reason The reason for cancellation
     * @return bool True if cancellation was successful
     * @throws TransactionException If cancellation fails
     */
    public function cancel(Transaction $transaction, ?string $reason = null): bool;

    /**
     * Get processor statistics and metadata.
     *
     * @return array Processor metadata including statistics
     */
    public function getMetadata(): array;

    /**
     * Check if the processor is enabled and can process transactions.
     *
     * @return bool True if processor is enabled
     */
    public function isEnabled(): bool;

    /**
     * Set additional context for processing.
     *
     * @param array $context Additional context data
     * @return self
     */
    public function setContext(array $context): self;

    /**
     * Get the processor name.
     *
     * @return string The processor name
     */
    public function getName(): string;
}
