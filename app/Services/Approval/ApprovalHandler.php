<?php


 namespace App\Services\Approval;

use App\Models\Transaction;
use App\Models\User;

interface ApprovalHandler
{
    public function setNext(ApprovalHandler $handler): ApprovalHandler;

    public function canHandle(Transaction $transaction): bool;

    public function handle(Transaction $transaction, User $user): array;
}
