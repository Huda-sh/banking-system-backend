<?php

namespace App\Patterns\Command\Interfaces;

use App\Models\Transaction;
use App\Exceptions\CommandException;

interface TransactionCommand
{
    /**
     * Execute the transaction command.
     *
     * @return bool True if execution was successful, false otherwise
     * @throws CommandException If execution fails
     */
    public function execute(): bool;

    /**
     * Undo the transaction command (roll back changes).
     *
     * @return bool True if undo was successful, false otherwise
     * @throws CommandException If undo fails
     */
    public function undo(): bool;

    /**
     * Get the transaction model associated with this command.
     *
     * @return Transaction The transaction model
     */
    public function getTransaction(): Transaction;

    /**
     * Validate the command parameters before execution.
     *
     * @return bool True if validation passes, false otherwise
     * @throws CommandException If validation fails
     */
    public function validate(): bool;

    /**
     * Get the command name for logging and debugging.
     *
     * @return string The command name
     */
    public function getName(): string;

    /**
     * Get the command metadata.
     *
     * @return array Command metadata
     */
    public function getMetadata(): array;

    /**
     * Set additional context for the command.
     *
     * @param array $context Additional context data
     * @return self
     */
    public function setContext(array $context): self;

    /**
     * Check if the command can be executed.
     *
     * @return bool True if command can be executed, false otherwise
     */
    public function canExecute(): bool;

    /**
     * Check if the command can be undone.
     *
     * @return bool True if command can be undone, false otherwise
     */
    public function canUndo(): bool;
}
