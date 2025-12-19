<?php

namespace App\Events;

use App\Models\Transaction;
use App\Models\ScheduledTransaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScheduledTransactionExecuted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Transaction $transaction,
        public ScheduledTransaction $scheduled
    ) {
        $this->transaction->load(['fromAccount', 'toAccount', 'initiatedBy']);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('scheduled-transactions'),
            new PrivateChannel('user.' . $this->transaction->initiated_by),
            new PrivateChannel('account.' . $this->transaction->to_account_id),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'scheduled.transaction.executed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'transaction_id' => $this->transaction->id,
            'scheduled_id' => $this->scheduled->id,
            'type' => $this->transaction->type->value,
            'amount' => $this->transaction->amount,
            'currency' => $this->transaction->currency,
            'frequency' => $this->scheduled->frequency,
            'execution_count' => $this->scheduled->execution_count,
            'next_execution' => $this->scheduled->next_execution ? $this->scheduled->next_execution->format('Y-m-d H:i:s') : null,
            'is_active' => $this->scheduled->is_active,
            'from_account' => $this->transaction->fromAccount ? $this->transaction->fromAccount->account_number : null,
            'to_account' => $this->transaction->toAccount ? $this->transaction->toAccount->account_number : null,
            'initiated_by' => $this->transaction->initiatedBy ? $this->transaction->initiatedBy->name : null,
            'execution_time' => now()->format('Y-m-d H:i:s')
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
