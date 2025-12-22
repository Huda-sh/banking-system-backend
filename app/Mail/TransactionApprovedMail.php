<?php

namespace App\Mail;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TransactionApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $transaction;
    public $user;
    public $role; // 'sender' أو 'receiver'

    public function __construct(Transaction $transaction, User $user, string $role)
    {
        $this->transaction = $transaction;
        $this->user = $user;
        $this->role = $role;
    }

    public function build()
    {
        $subject = $this->role === 'sender'
            ? "✅ تمت الموافقة على تحويلك - {$this->transaction->amount} {$this->transaction->currency}"
            : "✅ لقد استلمت تحويلًا - {$this->transaction->amount} {$this->transaction->currency}";

        return $this->subject($subject)
            ->view('emails.transaction-approved')
            ->with([
                'transaction' => $this->transaction,
                'user' => $this->user,
                'role' => $this->role,
                'amountFormatted' => number_format($this->transaction->amount, 2).' '.$this->transaction->currency,
                'approvalDate' => now()->format('Y-m-d H:i:s'),
            ]);
    }
}
