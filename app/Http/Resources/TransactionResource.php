<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;

class TransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        $baseData = [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->getLabel(),
            'direction' => $this->direction?->value,
            'direction_label' => $this->direction?->getLabel(),
            'status' => $this->status->value,
            'status_label' => $this->status->getLabel(),
            'status_color' => $this->status->getColor(),
            'amount' => (float) $this->amount,
            'fee' => (float) $this->fee,
            'net_amount' => (float) ($this->amount - $this->fee),
            'currency' => $this->currency,
            'description' => $this->description,
            'created_at' => $this->created_at ? $this->created_at->toISOString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            'approved_at' => $this->approved_at ? $this->approved_at->toISOString() : null,
            'metadata' => $this->metadata,
            'can_be_reversed' => $this->canBeReversed(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'requires_approval' => $this->requiresApproval(),
            'is_completed' => $this->status === TransactionStatus::COMPLETED,
            'is_failed' => $this->status === TransactionStatus::FAILED,
            'is_scheduled' => $this->isScheduled(),
        ];

        // Add account relationships if included
        if ($this->relationLoaded('fromAccount')) {
            $baseData['from_account'] = [
                'id' => $this->fromAccount->id,
                'account_number' => $this->fromAccount->account_number,
                'balance' => (float) $this->fromAccount->balance,
                'currency' => $this->fromAccount->currency,
                'type' => $this->fromAccount->accountType->name ?? 'Unknown'
            ];
        }

        if ($this->relationLoaded('toAccount')) {
            $baseData['to_account'] = [
                'id' => $this->toAccount->id,
                'account_number' => $this->toAccount->account_number,
                'balance' => (float) $this->toAccount->balance,
                'currency' => $this->toAccount->currency,
                'type' => $this->toAccount->accountType->name ?? 'Unknown'
            ];
        }

        // Add user relationships if included
        if ($this->relationLoaded('initiatedBy')) {
            $baseData['initiated_by'] = [
                'id' => $this->initiatedBy->id,
                'name' => $this->initiatedBy->full_name,
                'email' => $this->initiatedBy->email,
                'roles' => $this->initiatedBy->roles->pluck('name')
            ];
        }

        if ($this->relationLoaded('approvedBy')) {
            $baseData['approved_by'] = [
                'id' => $this->approvedBy->id,
                'name' => $this->approvedBy->full_name
            ];
        }

        // Add scheduled transaction data if exists
        if ($this->relationLoaded('scheduledTransaction') && $this->isScheduled()) {
            $baseData['scheduled_transaction'] = $this->scheduledTransaction ? [
                'id' => $this->scheduledTransaction->id,
                'frequency' => $this->scheduledTransaction->frequency,
                'frequency_label' => $this->scheduledTransaction->getScheduleDetails()['frequency_label'],
                'next_execution' => $this->scheduledTransaction->next_execution ? $this->scheduledTransaction->next_execution->toISOString() : null,
                'execution_count' => $this->scheduledTransaction->execution_count,
                'max_executions' => $this->scheduledTransaction->max_executions,
                'is_active' => $this->scheduledTransaction->is_active,
                'remaining_executions' => $this->scheduledTransaction->getScheduleDetails()['remaining_executions']
            ] : null;
        }

        // Add approval workflow data if exists
        if ($this->relationLoaded('approvals') && $this->requiresApproval()) {
            $baseData['approval_workflow'] = [
                'total_approvals' => $this->approvals->count(),
                'pending_count' => $this->approvals->where('status', 'pending')->count(),
                'approved_count' => $this->approvals->where('status', 'approved')->count(),
                'rejected_count' => $this->approvals->where('status', 'rejected')->count(),
                'approval_percent' => $this->approvals->count() > 0
                    ? round(($this->approvals->where('status', 'approved')->count() / $this->approvals->count()) * 100, 2)
                    : 0,
                'approvals' => $this->approvals->map(function ($approval) {
                    return [
                        'id' => $approval->id,
                        'approver' => $approval->approver ? [
                            'id' => $approval->approver->id,
                            'name' => $approval->approver->full_name,
                            'email' => $approval->approver->email
                        ] : null,
                        'level' => $approval->level,
                        'level_label' => $approval->getApprovalDetails()['level_label'] ?? $approval->level,
                        'status' => $approval->status,
                        'status_label' => $approval->getApprovalDetails()['status_label'] ?? $approval->status,
                        'status_color' => $approval->status->getColor(),
                        'due_at' => $approval->due_at ? $approval->due_at->toISOString() : null,
                        'approved_at' => $approval->approved_at ? $approval->approved_at->toISOString() : null,
                        'rejected_at' => $approval->rejected_at ? $approval->rejected_at->toISOString() : null,
                        'notes' => $approval->notes
                    ];
                })
            ];
        }

        return $baseData;
    }

    public function with($request): array
    {
        return [
            'meta' => [
                'api_version' => '1.0',
                'timestamp' => Carbon::now()->toISOString()
            ]
        ];
    }
}
