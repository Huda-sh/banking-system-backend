<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionFailed implements ShouldBroadcast
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
        return [
            new PrivateChannel('transactions'),
            new PrivateChannel('user.' . $this->transaction->initiated_by),
            new PrivateChannel('alerts'),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'transaction.failed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        $errorDetails = $this->transaction->metadata['error'] ?? 'Unknown error';
        $errorClass = $this->transaction->metadata['error_class'] ?? null;

        return [
            'id' => $this->transaction->id,
            'type' => $this->transaction->type->value,
            'amount' => $this->transaction->amount,
            'currency' => $this->transaction->currency,
            'error' => $errorDetails,
            'error_class' => $errorClass,
            'from_account' => $this->transaction->fromAccount ? $this->transaction->fromAccount->account_number : null,
            'to_account' => $this->transaction->toAccount ? $this->transaction->toAccount->account_number : null,
            'initiated_by' => $this->transaction->initiatedBy ? $this->transaction->initiatedBy->name : null,
            'created_at' => $this->transaction->created_at->format('Y-m-d H:i:s'),
            'requires_retry' => $this->shouldRetry($this->transaction),
            'retry_count' => $this->transaction->metadata['retry_count'] ?? 0
        ];
    }

    /**
     * Determine if the transaction should be retried.
     */
    private function shouldRetry(Transaction $transaction): bool
    {
        $retryableErrorClasses = [
            'ConnectionException',
            'TimeoutException',
            'NetworkException'
        ];

        $errorClass = $transaction->metadata['error_class'] ?? '';
        $retryCount = $transaction->metadata['retry_count'] ?? 0;

        return in_array($errorClass, $retryableErrorClasses) && $retryCount < 3;
    }

    /**
     * Determine if this event should broadcast.
     */
    public function broadcastWhen(): bool
    {
        return config('broadcasting.enabled', false) && $this->transaction->status === 'failed';
    }
}
