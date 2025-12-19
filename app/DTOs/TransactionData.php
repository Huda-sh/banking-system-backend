<?php

namespace App\DTOs;

use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use Spatie\DataTransferObject\DataTransferObject;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TransactionData extends DataTransferObject
{
    public function __construct(
        public ?int $from_account_id = null,
        public int $to_account_id,
        public float $amount,
        public string $currency = 'USD',
        public TransactionType $type,
        public TransactionStatus $status = TransactionStatus::PENDING,
        public float $fee = 0.00,
        public Direction $direction = Direction::OUTGOING,
        public int $initiated_by,
        public ?int $processed_by = null,
        public ?int $approved_by = null,
        public ?string $description = null,
        public ?string $ip_address = null,
        public array $metadata = [],
        public ?\DateTimeInterface $approved_at = null
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        $validator = Validator::make([
            'from_account_id' => $this->from_account_id,
            'to_account_id' => $this->to_account_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'type' => $this->type->value,
            'fee' => $this->fee,
            'direction' => $this->direction->value,
        ], [
            'from_account_id' => 'nullable|integer|exists:accounts,id',
            'to_account_id' => 'required|integer|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'currency' => 'required|string|regex:/^[A-Z]{3}$/',
            'type' => 'required|in:' . implode(',', TransactionType::toArray()),
            'direction' => 'required|in:' . implode(',', Direction::toArray()),
            'fee' => 'required|numeric|min:0|max:99999999.99'
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Additional validation based on transaction type
        $this->validateByType();
    }

    private function validateByType(): void
    {
        if ($this->type === TransactionType::DEPOSIT && $this->from_account_id) {
            throw new ValidationException(Validator::make([], []), 'Deposit transactions should not have a from_account_id');
        }

        if (in_array($this->type, [TransactionType::WITHDRAWAL, TransactionType::TRANSFER]) && !$this->from_account_id) {
            throw new ValidationException(Validator::make([], []), 'Withdrawal and transfer transactions require a from_account_id');
        }

        if ($this->fee > $this->amount * 0.1) { // 10% fee cap
            throw new ValidationException(Validator::make([], []), 'Fee cannot exceed 10% of transaction amount');
        }

        // direction-specific validation (basic)
        if ($this->direction === Direction::INCOMING && $this->type === TransactionType::WITHDRAWAL) {
            throw new ValidationException(Validator::make([], []), 'Incoming direction is incompatible with withdrawal type');
        }
    }

    public function toArray(): array
    {
        return [
            'from_account_id' => $this->from_account_id,
            'to_account_id' => $this->to_account_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'fee' => $this->fee,
            'direction' => $this->direction->value,
            'initiated_by' => $this->initiated_by,
            'processed_by' => $this->processed_by,
            'approved_by' => $this->approved_by,
            'description' => $this->description,
            'ip_address' => $this->ip_address,
            'metadata' => $this->metadata,
            'approved_at' => $this->approved_at ? $this->approved_at->format('Y-m-d H:i:s') : null
        ];
    }

    public function getTotalAmount(): float
    {
        return $this->amount + $this->fee;
    }

    public function isInternational(): bool
    {
        // This would require account data to determine
        return false;
    }

    public function requiresApproval(): bool
    {
        return $this->amount > 10000 || in_array($this->type, [
                TransactionType::INTERNATIONAL_TRANSFER,
                TransactionType::WIRE_TRANSFER
            ]);
    }
}
