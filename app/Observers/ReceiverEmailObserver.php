<?php

namespace App\Observers;

use App\Contracts\Observer;
use App\Contracts\Subject;
use App\Mail\TransactionApprovedMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ReceiverEmailObserver implements Observer
{
    public function update(Subject $subject): void
    {
        $transaction = $subject->getTransaction();

        $receiver = $transaction?->targetAccount?->user?->first()??null;

        if (!$receiver || !$receiver->email) {
            Log::warning('No receiver email found for transaction', [
                'transaction_id' => $transaction->id,
                'receiver_id' => $receiver?->id ?? 'null'
            ]);
            return;
        }

        try {
            Mail::to($receiver->email)->send(new TransactionApprovedMail(
                $transaction,
                $receiver,
                'receiver'
            ));

            Log::info('✅ Email sent to receiver successfully', [
                'to' => $receiver->email,
                'transaction_id' => $transaction->id
            ]);
        } catch (\Exception $e) {
            Log::error('❌ Email sending failed to receiver', [
                'error' => $e->getMessage(),
                'to' => $receiver->email,
                'transaction_id' => $transaction->id
            ]);
        }
    }
}
