<?php

namespace App\Interfaces;

use App\Models\Transaction;
use App\Models\User;
use App\Models\TransactionApproval;
use App\Exceptions\ApprovalException;
use App\DTOs\ApprovalData;

interface ApprovalWorkflowInterface
{
    /**
     * Start approval workflow for a transaction.
     *
     * @param Transaction $transaction The transaction requiring approval
     * @return array The created approval records
     * @throws ApprovalException If workflow cannot be started
     */
    public function startWorkflow(Transaction $transaction): array;

    /**
     * Approve a transaction by a user.
     *
     * @param Transaction $transaction The transaction to approve
     * @param User $approver The user approving the transaction
     * @param array $data Additional approval data
     * @return bool True if approval was successful
     * @throws ApprovalException If approval fails
     */
    public function approve(Transaction $transaction, User $approver, array $data = []): bool;

    /**
     * Reject a transaction by a user.
     *
     * @param Transaction $transaction The transaction to reject
     * @param User $approver The user rejecting the transaction
     * @param array $data Additional rejection data
     * @return bool True if rejection was successful
     * @throws ApprovalException If rejection fails
     */
    public function reject(Transaction $transaction, User $approver, array $data = []): bool;

    /**
     * Escalate an approval to a higher level.
     *
     * @param TransactionApproval $approval The approval to escalate
     * @param User $escalator The user escalating the approval
     * @param array $data Additional escalation data
     * @return TransactionApproval The new escalated approval
     * @throws ApprovalException If escalation fails
     */
    public function escalate(TransactionApproval $approval, User $escalator, array $data = []): TransactionApproval;

    /**
     * Cancel an approval workflow.
     *
     * @param Transaction $transaction The transaction with workflow to cancel
     * @param User $cancelledBy The user cancelling the workflow
     * @param string|null $reason The reason for cancellation
     * @return bool True if cancellation was successful
     * @throws ApprovalException If cancellation fails
     */
    public function cancelWorkflow(Transaction $transaction, User $cancelledBy, ?string $reason = null): bool;

    /**
     * Get pending approvals for a user.
     *
     * @param User $user The user to get approvals for
     * @param int $limit Maximum number of approvals to return
     * @return array Pending approvals for the user
     */
    public function getUserPendingApprovals(User $user, int $limit = 20): array;

    /**
     * Get approval workflow summary for a transaction.
     *
     * @param Transaction $transaction The transaction to get summary for
     * @return array Approval workflow summary
     */
    public function getWorkflowSummary(Transaction $transaction): array;

    /**
     * Process overdue approvals.
     *
     * @return array Results of processing overdue approvals
     */
    public function processOverdueApprovals(): array;

    /**
     * Check if a user can approve a transaction.
     *
     * @param Transaction $transaction The transaction to check
     * @param User $user The user to check
     * @return bool True if user can approve the transaction
     */
    public function canUserApprove(Transaction $transaction, User $user): bool;

    /**
     * Get approval statistics.
     *
     * @return array Approval statistics
     */
    public function getStatistics(): array;
}
