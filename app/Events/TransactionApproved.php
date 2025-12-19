<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionApproved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Transaction $transaction)
    {
        $this->transaction->load(['approvedBy', 'fromAccount', 'toAccount']);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('transactions'),
            new PrivateChannel('user.' . $this->transaction->initiated_by),
            new PrivateChannel('approvals'),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'transaction.approved';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->transaction->id,
            'type' => $this->transaction->type->value,
            'amount' => $this->transaction->amount,
            'currency' => $this->transaction->currency,
            'approved_by' => $this->transaction->approvedBy ? $this->transaction->approvedBy->name : null,
            'approved_at' => $this->transaction->approved_at->format('Y-m-d H:i:s'),
            'from_account' => $this->transaction->fromAccount ? $this->transaction->fromAccount->account_number : null,
            'to_account' => $this->transaction->toAccount ? $this->transaction->toAccount->account_number : null,
            'approval_workflow' => $this->transaction->getApprovalStatusSummary()
        ];
    }

    /**
     * Determine if this event should broadcast.
     */
    public function broadcastWhen(): bool
    {
        return config('broadcasting.enabled', false) && $this->transaction->status === 'approved';
    }
}
