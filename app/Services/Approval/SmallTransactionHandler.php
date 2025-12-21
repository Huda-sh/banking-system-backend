<?php

namespace App\Services\Approval;

use App\Models\Transaction;
use App\Models\User;
use App\Observables\TransactionApprovalSubject;
use App\Observers\ReceiverEmailObserver;
use App\Observers\SenderEmailObserver;

class SmallTransactionHandler extends AbstractApprovalHandler
{
    public function canHandle(Transaction $transaction): bool
    {
        return $transaction->amount <= 1000;
    }

    public function handle(Transaction $transaction, User $user): array
    {
        if ($this->canHandle($transaction)) {

            $subject = new TransactionApprovalSubject($transaction);

            $subject->attach(new SenderEmailObserver());
            $subject->attach(new ReceiverEmailObserver());

            $subject->approveTransaction();
            return [
                'approved' => true,
                'message' => 'Auto-approved: Small transaction.',
                'requires_approval' => false,
            ];
        }

        return $this->passToNext($transaction, $user);
    }
}
