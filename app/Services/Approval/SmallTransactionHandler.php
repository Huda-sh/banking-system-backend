<?php

 namespace App\Services\Approval;

use App\Models\Transaction;
use App\Models\User;

class SmallTransactionHandler extends AbstractApprovalHandler
{
    public function canHandle(Transaction $transaction): bool
    {
        return $transaction->amount <= 1000;
    }

    public function handle(Transaction $transaction, User $user): array
    {
        if ($this->canHandle($transaction)) {
            return [
                'approved' => true,
                'message' => 'Auto-approved: Small transaction.',
                'requires_approval' => false,
            ];
        }

        return $this->passToNext($transaction, $user);
    }
}
