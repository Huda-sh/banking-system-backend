<?php

namespace App\Services\Approval;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MediumTransactionHandler extends AbstractApprovalHandler
{
    public function canHandle(Transaction $transaction): bool
    {
        return $transaction->amount > 1000 && $transaction->amount <= 10000;
    }

    public function handle(Transaction $transaction, User $user): array
    {
        if (!$this->canHandle($transaction)) {
            return $this->passToNext($transaction, $user);
        }

//        if ($user->hasRole('Manager') || $user->hasRole('Admin')) {
//            return [
//                'approved' => true,
//                'message' => 'Approved by Manager/Admin.',
//                'requires_approval' => false,
//            ];
//        }

        DB::transaction(function () use ($transaction, $user) {
            Approval::create([
                'entity_type' => 'transaction',
                'entity_id' => $transaction->id,
                'requested_by' => $user->id,
                'approved_by' => null,
                'status' => ApprovalStatus::PENDING,
            ]);

            $transaction->update(['status' => 'pending']);
        });

        return [
            'approved' => false,
            'requires_approval' => true,
            'allowed_roles' => ['Manager', 'Admin'],
            'message' => 'Requires Manager or Admin approval.',
        ];
    }
}
