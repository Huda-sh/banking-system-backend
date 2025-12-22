<?php


namespace App\Services\Approval;

use App\Models\Transaction;
use App\Models\User;

abstract class AbstractApprovalHandler
{
    protected ?AbstractApprovalHandler $nextHandler = null;

    public function setNext(AbstractApprovalHandler $handler): AbstractApprovalHandler
    {
        $this->nextHandler = $handler;
        return $handler;
    }

    abstract public function canHandle(Transaction $transaction): bool;

    abstract public function handle(Transaction $transaction, User $user): array;

    protected function passToNext(Transaction $transaction, User $user): array
    {
        if ($this->nextHandler) {
            return $this->nextHandler->handle($transaction, $user);
        }

        return [
            'approved' => false,
            'requires_approval' => true,
            'message' => 'No handler available for this transaction amount.',
        ];
    }
}
