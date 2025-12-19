<?php

namespace App\Patterns\Command;

use App\Patterns\Command\Interfaces\TransactionCommand;
use App\Exceptions\CommandException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class CommandInvoker
{
    /**
     * Maximum number of undo operations to keep in history.
     */
    const MAX_UNDO_HISTORY = 100;

    /**
     * Command execution timeout in seconds.
     */
    const EXECUTION_TIMEOUT = 30;

    private array $commandHistory = [];
    private array $undoHistory = [];

    public function __construct()
    {
        $this->loadCommandHistory();
    }

    /**
     * Execute a command with timeout and error handling.
     *
     * @throws CommandException If command execution fails or times out
     */
    public function execute(TransactionCommand $command): mixed
    {
        $commandName = $command->getName();
        $startTime = microtime(true);

        Log::debug('CommandInvoker: Executing command', [
            'command' => $commandName,
            'start_time' => $startTime
        ]);

        try {
            // Set timeout for command execution
            $result = $this->executeWithTimeout($command);

            $executionTime = microtime(true) - $startTime;
            $transaction = $command->getTransaction();

            Log::info('CommandInvoker: Command executed successfully', [
                'command' => $commandName,
                'transaction_id' => $transaction->id,
                'execution_time' => round($executionTime, 3) . 's',
                'status' => $transaction->status->value
            ]);

            // Add to command history
            $this->addToCommandHistory($command, $transaction);

            return $result;

        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;

            Log::error('CommandInvoker: Command execution failed', [
                'command' => $commandName,
                'execution_time' => round($executionTime, 3) . 's',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);

            throw new CommandException(
                "Command {$commandName} failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Execute command with timeout protection.
     */
    private function executeWithTimeout(TransactionCommand $command): bool
    {
        $timeout = self::EXECUTION_TIMEOUT;

        // Use pcntl for timeout if available (Unix systems)
        if (function_exists('pcntl_alarm')) {
            pcntl_signal(SIGALRM, function() use ($command) {
                throw new CommandException("Command {$command->getName()} timed out after " . self::EXECUTION_TIMEOUT . " seconds");
            });

            pcntl_alarm($timeout);
            $result = $command->execute();
            pcntl_alarm(0); // Cancel the alarm

            return $result;
        }

        // Fallback to basic timeout check
        $startTime = time();
        $result = $command->execute();

        if (time() - $startTime > $timeout) {
            throw new CommandException("Command {$command->getName()} timed out after {$timeout} seconds");
        }

        return $result;
    }

    /**
     * Undo the last executed command.
     *
     * @throws CommandException If no commands to undo or undo fails
     */
    public function undo(): bool
    {
        if (empty($this->commandHistory)) {
            throw new CommandException('No commands to undo');
        }

        $lastCommand = array_pop($this->commandHistory);
        $command = $lastCommand['command'];

        Log::debug('CommandInvoker: Undoing command', [
            'command' => $command->getName(),
            'transaction_id' => $lastCommand['transaction_id']
        ]);

        try {
            $result = $command->undo();

            Log::info('CommandInvoker: Command undone successfully', [
                'command' => $command->getName(),
                'transaction_id' => $lastCommand['transaction_id']
            ]);

            // Add to undo history
            $this->addToUndoHistory($lastCommand);

            return $result;

        } catch (\Exception $e) {
            Log::error('CommandInvoker: Command undo failed', [
                'command' => $command->getName(),
                'transaction_id' => $lastCommand['transaction_id'],
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);

            throw new CommandException(
                "Failed to undo command {$command->getName()}: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Get command execution history.
     */
    public function getCommandHistory(int $limit = 50): array
    {
        return array_slice($this->commandHistory, -$limit);
    }

    /**
     * Get undo history.
     */
    public function getUndoHistory(int $limit = 20): array
    {
        return array_slice($this->undoHistory, -$limit);
    }

    /**
     * Clear command history.
     */
    public function clearHistory(): void
    {
        $this->commandHistory = [];
        $this->undoHistory = [];
        $this->saveCommandHistory();
    }

    /**
     * Add command to history.
     */
    private function addToCommandHistory(TransactionCommand $command, $transaction): void
    {
        $this->commandHistory[] = [
            'id' => Str::uuid()->toString(),
            'command' => $command,
            'transaction_id' => $transaction->id,
            'executed_at' => now(),
            'metadata' => $command->getMetadata()
        ];

        // Limit history size
        if (count($this->commandHistory) > self::MAX_UNDO_HISTORY) {
            array_shift($this->commandHistory);
        }

        $this->saveCommandHistory();
    }

    /**
     * Add command to undo history.
     */
    private function addToUndoHistory(array $commandData): void
    {
        $this->undoHistory[] = [
            'id' => Str::uuid()->toString(),
            'command' => $commandData['command'],
            'transaction_id' => $commandData['transaction_id'],
            'undone_at' => now(),
            'metadata' => $commandData['command']->getMetadata()
        ];

        // Limit undo history size
        if (count($this->undoHistory) > self::MAX_UNDO_HISTORY) {
            array_shift($this->undoHistory);
        }

        $this->saveCommandHistory();
    }

    /**
     * Save command history to cache or session.
     */
    private function saveCommandHistory(): void
    {
        $history = [
            'command_history' => array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'command_name' => $item['command']->getName(),
                    'transaction_id' => $item['transaction_id'],
                    'executed_at' => $item['executed_at']->format('Y-m-d H:i:s'),
                    'metadata' => $item['metadata']
                ];
            }, $this->commandHistory),
            'undo_history' => array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'command_name' => $item['command']->getName(),
                    'transaction_id' => $item['transaction_id'],
                    'undone_at' => $item['undone_at']->format('Y-m-d H:i:s'),
                    'metadata' => $item['metadata']
                ];
            }, $this->undoHistory),
            'saved_at' => now()->format('Y-m-d H:i:s')
        ];

        // In production, this would save to cache or database
        // For now, we'll use session
        session()->put('command_history', $history);
    }

    /**
     * Load command history from cache or session.
     */
    private function loadCommandHistory(): void
    {
        $history = session()->get('command_history');

        if ($history) {
            Log::debug('CommandInvoker: Loaded command history', [
                'command_count' => count($history['command_history'] ?? []),
                'undo_count' => count($history['undo_history'] ?? []),
                'saved_at' => $history['saved_at'] ?? 'unknown'
            ]);
        }
    }

    /**
     * Get statistics about command execution.
     */
    public function getStatistics(): array
    {
        $totalCommands = count($this->commandHistory);
        $totalUndo = count($this->undoHistory);

        $commandTypes = array_count_values(array_map(function ($item) {
            return $item['command']->getName();
        }, $this->commandHistory));

        $successRate = $totalCommands > 0
            ? round((count(array_filter($this->commandHistory, fn($item) => $item['transaction_id'] ?? false)) / $totalCommands) * 100, 2)
            : 0;

        return [
            'total_commands_executed' => $totalCommands,
            'total_undo_operations' => $totalUndo,
            'command_types' => $commandTypes,
            'success_rate' => $successRate,
            'average_execution_time' => $this->calculateAverageExecutionTime(),
            'last_execution_time' => $this->commandHistory ? end($this->commandHistory)['executed_at']->format('Y-m-d H:i:s') : null
        ];
    }

    /**
     * Calculate average command execution time.
     */
    private function calculateAverageExecutionTime(): float
    {
        if (empty($this->commandHistory)) {
            return 0.0;
        }

        $totalTime = 0;
        $count = 0;

        foreach ($this->commandHistory as $item) {
            if (isset($item['execution_time'])) {
                $totalTime += $item['execution_time'];
                $count++;
            }
        }

        return $count > 0 ? round($totalTime / $count, 3) : 0.0;
    }

    /**
     * Execute a batch of commands.
     *
     * @throws CommandException If any command in the batch fails
     */
    public function executeBatch(array $commands): array
    {
        $results = [];
        $failedCommands = [];

        foreach ($commands as $command) {
            try {
                $result = $this->execute($command);
                $results[] = [
                    'command' => $command->getName(),
                    'success' => true,
                    'result' => $result,
                    'transaction_id' => $command->getTransaction()->id
                ];
            } catch (\Exception $e) {
                $failedCommands[] = [
                    'command' => $command->getName(),
                    'error' => $e->getMessage(),
                    'exception' => get_class($e)
                ];

                Log::error('CommandInvoker: Batch command failed', [
                    'command' => $command->getName(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        if (!empty($failedCommands)) {
            $errorMessages = array_map(fn($cmd) => "{$cmd['command']}: {$cmd['error']}", $failedCommands);
            throw new CommandException("Batch execution failed. " . implode(', ', $errorMessages));
        }

        return $results;
    }
}
