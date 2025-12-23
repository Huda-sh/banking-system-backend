<?php

namespace App\Observers;

use App\Contracts\Observer;
use App\Contracts\Subject;
use App\Mail\TransactionApprovedMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SenderEmailObserver implements Observer
{
    public function update(Subject $subject): void
    {
        $transaction = $subject->getTransaction();

         $sender = $transaction?->sourceAccount?->user?->first()??null;

        if (!$sender || !$sender->email) {
            Log::warning('No sender email found for transaction', [
                'transaction_id' => $transaction->id,
                'sender_id' => $sender?->id ?? 'null'
            ]);
            return;
        }

        try {
            Mail::to($sender->email)->send(new TransactionApprovedMail(
                $transaction,
                $sender,
                'sender'
            ));

            Log::info('✅ Email sent to sender successfully', [
                'to' => $sender->email,
                'transaction_id' => $transaction->id
            ]);
        } catch (\Exception $e) {
            Log::error('❌ Email sending failed to sender', [
                'error' => $e->getMessage(),
                'to' => $sender->email,
                'transaction_id' => $transaction->id
            ]);
        }
    }
}
