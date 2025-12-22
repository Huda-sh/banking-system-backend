<?php


namespace App\Observables;

use App\Contracts\Observer;
use App\Contracts\Subject;
use App\Models\Transaction;

class TransactionApprovalSubject implements Subject
{
    private Transaction $transaction;
    private array $observers = [];

    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    public function getTransaction(): Transaction
    {
        return $this->transaction;
    }

    public function attach(Observer $observer): void
    {
        $this->observers[] = $observer;
    }

    public function detach(Observer $observer): void
    {
        $this->observers = array_filter($this->observers, fn($o) => $o !== $observer);
    }

    public function notify(): void
    {
        foreach ($this->observers as $observer) {
            $observer->update($this);
        }
    }
}
