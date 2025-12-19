<?php

namespace App\Patterns\ChainOfResponsibility\Interfaces;

use App\Models\Transaction;
use App\Exceptions\HandlerException;

interface TransactionHandler
{
    /**
     * Set the next handler in the chain.
     */
    public function setNext(TransactionHandler $handler): TransactionHandler;

    /**
     * Handle the transaction request.
     *
     * @return bool True if processing can continue, false if approval is required
     * @throws HandlerException If validation fails
     */
    public function handle(Transaction $transaction): bool;

    /**
     * Get the handler name for logging and debugging.
     */
    public function getName(): string;

    /**
     * Get the priority of this handler (lower number = higher priority).
     */
    public function getPriority(): int;
}
