<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use App\Models\TransactionApproval;
use App\Enums\ApprovalStatus;

class ApprovalResource extends JsonResource
{
    public function toArray($request): array
    {
        $baseData = [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'level' => $this->level,
            'level_label' => $this->getApprovalDetails()['level_label'] ?? $this->level,
            'status' => $this->status,
            'status_label' => $this->getApprovalDetails()['status_label'] ?? $this->status,
            'status_color' => $this->status->getColor(),
            'notes' => $this->notes,
            'due_at' => $this->due_at ? $this->due_at->toISOString() : null,
            'created_at' => $this->created_at ? $this->created_at->toISOString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            'is_overdue' => $this->due_at && $this->due_at->isPast() && $this->status === ApprovalStatus::PENDING,
            'time_remaining' => $this->getTimeRemaining(),
            'can_be_approved' => $this->status === ApprovalStatus::PENDING,
            'can_be_rejected' => $this->status === ApprovalStatus::PENDING,
            'can_be_escalated' => $this->status === ApprovalStatus::PENDING
        ];

        // Add transaction relationship if included
        if ($this->relationLoaded('transaction')) {
            $baseData['transaction'] = new TransactionResource($this->transaction);
        }

        // Add approver relationship if included
        if ($this->relationLoaded('approver')) {
            $baseData['approver'] = [
                'id' => $this->approver->id,
                'name' => $this->approver->full_name,
                'email' => $this->approver->email,
                'roles' => $this->approver->roles->pluck('name'),
                'last_login' => $this->approver->last_login_at ? $this->approver->last_login_at->toISOString() : null
            ];
        }

        // Add escalation details if exists
        if ($this->escalated_from_id) {
            $baseData['escalation'] = [
                'escalated_from_id' => $this->escalated_from_id,
                'escalated_at' => $this->escalated_at ? $this->escalated_at->toISOString() : null,
                'escalated_by' => $this->escalated_by ? $this->escalated_by->full_name : null
            ];
        }

        return $baseData;
    }

    private function getTimeRemaining(): ?string
    {
        if (!$this->due_at || $this->status !== ApprovalStatus::PENDING) {
            return null;
        }

        $diff = now()->diffInHours($this->due_at, false);

        if ($diff < 0) {
            return 'Overdue';
        }

        if ($diff < 24) {
            return "{$diff} hours remaining";
        }

        $days = now()->diffInDays($this->due_at);
        return "{$days} days remaining";
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
