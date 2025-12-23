<?php

namespace App\Services\Approval;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class VeryLargeTransactionHandler extends AbstractApprovalHandler
{
    public function canHandle(Transaction $transaction): bool
    {
        return $transaction->amount > 50000;
    }

    public function handle(Transaction $transaction, User $user): array
    {
        if ($user->hasRole('Admin')) {
            return [
                'approved' => true,
                'message' => 'Approved by Admin (Very Large).',
                'requires_approval' => false,
            ];
        }

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
            'allowed_roles' => ['Admin'],
            'message' => 'Very large transaction requires Admin approval.',
        ];
    }
}
