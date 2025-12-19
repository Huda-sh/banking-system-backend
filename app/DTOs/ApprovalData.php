<?php

namespace App\DTOs;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalLevel;
use Spatie\DataTransferObject\DataTransferObject;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ApprovalData extends DataTransferObject
{
    public function __construct(
        public int $transaction_id,
        public int $approver_id,
        public ApprovalLevel $level,
        public ApprovalStatus $status = ApprovalStatus::PENDING,
        public ?string $notes = null,
        public ?\DateTimeInterface $approved_at = null,
        public ?\DateTimeInterface $rejected_at = null,
        public ?\DateTimeInterface $due_at = null,
        public ?int $escalated_from_id = null
    ) {
        $this->due_at = $this->due_at ?? now()->addHours($this->getDefaultTimeout());
        $this->validate();
    }

    private function validate(): void
    {
        $validator = Validator::make([
            'transaction_id' => $this->transaction_id,
            'approver_id' => $this->approver_id,
            'level' => $this->level->value,
            'status' => $this->status->value,
            'due_at' => $this->due_at
        ], [
            'transaction_id' => 'required|integer|exists:transactions,id',
            'approver_id' => 'required|integer|exists:users,id',
            'level' => 'required|in:' . implode(',', ApprovalLevel::toArray()),
            'status' => 'required|in:' . implode(',', ApprovalStatus::toArray()),
            'due_at' => 'nullable|date|after:now'
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function getDefaultTimeout(): int
    {
        return match($this->level) {
            ApprovalLevel::TELLER => 24,
            ApprovalLevel::MANAGER => 36,
            ApprovalLevel::ADMIN => 48,
            ApprovalLevel::COMPLIANCE_OFFICER, ApprovalLevel::RISK_MANAGER => 72,
            ApprovalLevel::SENIOR_MANAGER => 96,
            ApprovalLevel::EXECUTIVE => 120,
            default => 48
        };
    }

    public function toArray(): array
    {
        return [
            'transaction_id' => $this->transaction_id,
            'approver_id' => $this->approver_id,
            'level' => $this->level->value,
            'level_label' => $this->level->getLabel(),
            'status' => $this->status->value,
            'status_label' => $this->status->getLabel(),
            'notes' => $this->notes,
            'approved_at' => $this->approved_at ? $this->approved_at->format('Y-m-d H:i:s') : null,
            'rejected_at' => $this->rejected_at ? $this->rejected_at->format('Y-m-d H:i:s') : null,
            'due_at' => $this->due_at ? $this->due_at->format('Y-m-d H:i:s') : null,
            'escalated_from_id' => $this->escalated_from_id,
            'is_overdue' => $this->isOverdue()
        ];
    }

    public function isOverdue(): bool
    {
        return $this->due_at && now()->gt($this->due_at);
    }

    public function getTimeRemaining(): ?string
    {
        if (!$this->due_at || $this->status !== ApprovalStatus::PENDING) {
            return null;
        }

        $diff = now()->diff($this->due_at);

        if ($diff->days > 0) {
            return "{$diff->days} days remaining";
        }

        if ($diff->h > 0) {
            return "{$diff->h} hours remaining";
        }

        if ($diff->i > 0) {
            return "{$diff->i} minutes remaining";
        }

        return 'Due soon';
    }

    public function getEscalationPath(): array
    {
        return $this->level->getEscalationPath();
    }

    public function getOverridingLevels(): array
    {
        return $this->level->getOverridingLevels();
    }
}
