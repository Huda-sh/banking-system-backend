<?php

namespace App\Exceptions;

use App\Models\Transaction;
use App\Enums\ApprovalLevel;
use Exception;
use Illuminate\Http\Response;

class ApprovalException extends TransactionException
{
    public function __construct(
        public Transaction $transaction,
        public ?ApprovalLevel $requiredLevel = null,
        string $message = '',
        int $code = 0,
        Exception $previous = null
    ) {
        $message = $message ?: sprintf(
            'Transaction #%d requires approval. Current status: %s',
            $transaction->id,
            $transaction->status->value
        );

        parent::__construct($message, $code, $previous);
    }

    public function render($request): Response
    {
        return response()->json([
            'error' => 'Approval Required',
            'message' => $this->getMessage(),
            'transaction_id' => $this->transaction->id,
            'amount' => $this->transaction->amount,
            'currency' => $this->transaction->currency,
            'current_status' => $this->transaction->status->value,
            'required_approval_level' => $this->requiredLevel?->value,
            'required_approval_level_label' => $this->requiredLevel?->getLabel(),
            'approval_workflow' => $this->getApprovalWorkflowSummary()
        ], 403);
    }

    private function getApprovalWorkflowSummary(): array
    {
        $summary = $this->transaction->getApprovalStatusSummary();

        return [
            'total_approvals' => $summary['total'],
            'pending_approvals' => $summary['pending'],
            'approved_approvals' => $summary['approved'],
            'rejected_approvals' => $summary['rejected'],
            'status' => $summary['status'],
            'can_be_approved_by_current_user' => $this->transaction->getNextPendingApproval()?->approver_id === auth()->id()
        ];
    }

    public function getDetails(): array
    {
        $details = parent::getDetails();

        $details['transaction_data'] = [
            'transaction_id' => $this->transaction->id,
            'amount' => $this->transaction->amount,
            'currency' => $this->transaction->currency,
            'type' => $this->transaction->type->value,
            'current_status' => $this->transaction->status->value
        ];

        if ($this->requiredLevel) {
            $details['approval_data'] = [
                'required_level' => $this->requiredLevel->value,
                'required_level_label' => $this->requiredLevel->getLabel(),
                'max_amount' => $this->requiredLevel->getMaxApprovalAmount(),
                'required_roles' => $this->requiredLevel->getRequiredRoles()
            ];
        }

        $details['workflow_data'] = $this->getApprovalWorkflowSummary();

        return $details;
    }
}
