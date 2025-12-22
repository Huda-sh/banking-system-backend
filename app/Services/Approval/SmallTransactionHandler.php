<?php

namespace App\Services\Approval;

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
                // 1. إنشاء سجل موافقة
                Approval::create([
                    'entity_type' => 'transaction',
                    'entity_id' => $transaction->id,
                    'requested_by' => $user->id,
                    'approved_by' => $user->id,
                    'status' => 1, // 1 = approved
                    'approved_at' => now()
                ]);

                // 2. تحديث حالة المعاملة
                $transaction->update(['status' => 'approved']);

                // 3. إرسال الإيميلات
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
