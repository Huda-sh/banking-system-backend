<?php
// app/Services/Approval/AbstractApprovalHandler.php
namespace App\Services\Approval;

use App\Models\Transaction;
use App\Models\User;

abstract class AbstractApprovalHandler implements ApprovalHandler
{
    protected ?ApprovalHandler $nextHandler = null;

    public function setNext(ApprovalHandler $handler): ApprovalHandler
    {
        $this->nextHandler = $handler;
        return $handler;
    }

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
