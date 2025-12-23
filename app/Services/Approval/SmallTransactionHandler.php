<?php

namespace App\Services\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\TransactionStatus;
use App\Models\Approval;
use App\Models\Transaction;
use App\Models\User;
use App\Observables\TransactionApprovalSubject;
use App\Observers\ReceiverEmailObserver;
use App\Observers\SenderEmailObserver;
use Illuminate\Support\Facades\DB;

class SmallTransactionHandler extends AbstractApprovalHandler
{
    public function canHandle(Transaction $transaction): bool
    {
        return $transaction->amount <= 1000;
    }

    public function handle(Transaction $transaction, User $user): array
    {
        if ($this->canHandle($transaction)) {
            return DB::transaction(function () use ($transaction, $user) {

                Approval::create([
                    'entity_type' => 'transaction',
                    'entity_id' => $transaction->id,
                    'requested_by' => $user->id,
                    'approved_by' => $user->id,
                    'status' => ApprovalStatus::PENDING,
                    'approved_at' => now()
                ]);

                 $transaction->update(['status' => 'approved']);

                 $subject = new TransactionApprovalSubject($transaction);
                $subject->attach(new SenderEmailObserver());
                $subject->attach(new ReceiverEmailObserver());
                $subject->notify();

                return [
                    'approved' => true,
                    'message' => 'تمت الموافقة وإرسال الإيميلات بنجاح',
                    'requires_approval' => false,
                ];
            });
        }

        return $this->passToNext($transaction, $user);
    }
}
