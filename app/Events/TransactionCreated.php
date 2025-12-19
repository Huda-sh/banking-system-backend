<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Transaction $transaction)
    {
        $this->transaction->load(['fromAccount', 'toAccount', 'initiatedBy']);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('transactions'),
            new PrivateChannel('user.' . $this->transaction->initiated_by),
        ];

        if ($this->transaction->from_account_id) {
            $channels[] = new PrivateChannel('account.' . $this->transaction->from_account_id);
        }

        if ($this->transaction->to_account_id) {
            $channels[] = new PrivateChannel('account.' . $this->transaction->to_account_id);
        }

        return $channels;
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'transaction.created';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->transaction->id,
            'type' => $this->transaction->type->value,
            'status' => $this->transaction->status->value,
            'amount' => $this->transaction->amount,
            'currency' => $this->transaction->currency,
            'description' => $this->transaction->description,
            'from_account' => $this->transaction->fromAccount ? $this->transaction->fromAccount->account_number : null,
            'to_account' => $this->transaction->toAccount ? $this->transaction->toAccount->account_number : null,
            'initiated_by' => $this->transaction->initiatedBy ? $this->transaction->initiatedBy->name : null,
            'created_at' => $this->transaction->created_at->toDateTimeString(),
            'requires_approval' => $this->transaction->requiresApproval(),
            'approval_status' => $this->transaction->requiresApproval() ?
                $this->transaction->getApprovalStatusSummary()['status'] : null
        ];
    }

    /**
     * Determine if this event should broadcast.
     */
    public function broadcastWhen(): bool
    {
        return config('broadcasting.enabled', false);
    }
}
