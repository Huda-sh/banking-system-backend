<?php

namespace App\Patterns\Decorator\Interfaces;

use App\Models\Transaction;
use App\Exceptions\ProcessorException;

interface TransactionProcessor
{
    /**
     * Process a transaction with all its validations and business logic.
     *
     * @param Transaction $transaction The transaction to process
     * @return bool True if processing was successful, false otherwise
     * @throws ProcessorException If processing fails
     */
    public function process(Transaction $transaction): bool;

    /**
     * Validate the transaction before processing.
     *
     * @param Transaction $transaction The transaction to validate
     * @return bool True if validation passes, false otherwise
     * @throws ProcessorException If validation fails
     */
    public function validate(Transaction $transaction): bool;

    /**
     * Get the processor name for logging and debugging.
     *
     * @return string The processor name
     */
    public function getName(): string;

    /**
     * Get processor metadata including configuration and statistics.
     *
     * @return array Processor metadata
     */
    public function getMetadata(): array;

    /**
     * Set context data for the processor.
     *
     * @param array $context Context data to set
     * @return self
     */
    public function setContext(array $context): self;

    /**
     * Check if the processor is enabled and can process transactions.
     *
     * @return bool True if processor is enabled
     */
    public function isEnabled(): bool;
}
