<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SimpleTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->getTypeLabel(),
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'amount' => (float) $this->amount,
            'fee' => (float) $this->fee,
            'net_amount' => (float) ($this->amount - $this->fee),
            'currency' => $this->currency,
            'description' => $this->description,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'from_account' => $this->whenLoaded('fromAccount', function () {
                return [
                    'id' => $this->fromAccount->id,
                    'account_number' => $this->fromAccount->account_number
                ];
            }),
            'to_account' => $this->whenLoaded('toAccount', function () {
                return [
                    'id' => $this->toAccount->id,
                    'account_number' => $this->toAccount->account_number
                ];
            })
        ];
    }

    /**
     * Get the type label for display.
     */
    protected function getTypeLabel(): string
    {
        return match($this->type) {
            'deposit' => 'Deposit',
            'withdrawal' => 'Withdrawal',
            'transfer' => 'Transfer',
            'scheduled' => 'Scheduled',
            'loan_payment' => 'Loan Payment',
            'interest_payment' => 'Interest Payment',
            'fee_charge' => 'Fee Charge',
            default => ucfirst($this->type)
        };
    }

    /**
     * Get the status label for display.
     */
    protected function getStatusLabel(): string
    {
        return match($this->status) {
            'pending' => 'Pending',
            'pending_approval' => 'Pending Approval',
            'approved' => 'Approved',
            'completed' => 'Completed',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            'reversed' => 'Reversed',
            default => ucfirst($this->status)
        };
    }
}
