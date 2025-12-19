<?php

namespace App\Interfaces;

use App\Models\ScheduledTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Exceptions\TransactionException;
use App\DTOs\ScheduledTransactionData;
use Carbon\Carbon;

interface SchedulerInterface
{
    /**
     * Process all due scheduled transactions.
     *
     * @return array Results of processing due transactions
     */
    public function processDueTransactions(): array;

    /**
     * Create a new scheduled transaction.
     *
     * @param ScheduledTransactionData $data The scheduled transaction data
     * @param User $initiatedBy The user creating the schedule
     * @return ScheduledTransaction The created scheduled transaction
     * @throws TransactionException If creation fails
     */
    public function createScheduledTransaction(ScheduledTransactionData $data, User $initiatedBy): ScheduledTransaction;

    /**
     * Update an existing scheduled transaction.
     *
     * @param ScheduledTransaction $scheduled The scheduled transaction to update
     * @param array $data The update data
     * @return ScheduledTransaction The updated scheduled transaction
     * @throws TransactionException If update fails
     */
    public function updateScheduledTransaction(ScheduledTransaction $scheduled, array $data): ScheduledTransaction;

    /**
     * Cancel a scheduled transaction.
     *
     * @param ScheduledTransaction $scheduled The scheduled transaction to cancel
     * @param User $cancelledBy The user cancelling the schedule
     * @param string|null $reason The reason for cancellation
     * @return bool True if cancellation was successful
     * @throws TransactionException If cancellation fails
     */
    public function cancelScheduledTransaction(ScheduledTransaction $scheduled, User $cancelledBy, ?string $reason = null): bool;

    /**
     * Get upcoming scheduled transactions for a user.
     *
     * @param User $user The user to get schedules for
     * @param ?Carbon $startDate Start date for filtering
     * @param ?Carbon $endDate End date for filtering
     * @param int $limit Maximum number of schedules to return
     * @return array Upcoming scheduled transactions
     */
    public function getUserUpcomingSchedules(User $user, ?Carbon $startDate = null, ?Carbon $endDate = null, int $limit = 20): array;

    /**
     * Get schedule execution history.
     *
     * @param ScheduledTransaction $scheduled The scheduled transaction to get history for
     * @param int $limit Maximum number of executions to return
     * @return array Schedule execution history
     */
    public function getScheduleHistory(ScheduledTransaction $scheduled, int $limit = 50): array;

    /**
     * Reactivate a scheduled transaction.
     *
     * @param ScheduledTransaction $scheduled The scheduled transaction to reactivate
     * @param ?Carbon $nextExecution The next execution date
     * @return ScheduledTransaction The reactivated scheduled transaction
     * @throws TransactionException If reactivation fails
     */
    public function reactivateSchedule(ScheduledTransaction $scheduled, ?Carbon $nextExecution = null): ScheduledTransaction;

    /**
     * Get schedule statistics.
     *
     * @return array Schedule statistics
     */
    public function getStatistics(): array;

    /**
     * Process a single scheduled transaction execution.
     *
     * @param ScheduledTransaction $scheduled The scheduled transaction to execute
     * @return Transaction The executed transaction
     * @throws TransactionException If execution fails
     */
    public function processSingleScheduledTransaction(ScheduledTransaction $scheduled): Transaction;

    /**
     * Check if a scheduled transaction can be executed.
     *
     * @param ScheduledTransaction $scheduled The scheduled transaction to check
     * @return bool True if the transaction can be executed
     */
    public function canExecuteScheduledTransaction(ScheduledTransaction $scheduled): bool;
}
