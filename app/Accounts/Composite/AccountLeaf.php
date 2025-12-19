<?php

namespace App\Accounts\Composite;

use App\Accounts\Contracts\AccountComponent;
use App\Models\Account;

class AccountLeaf implements AccountComponent {
    public function __construct(private Account $account) {}

    public function getBalance(): float
    {
        // TODO: implement get balance for account leaf using the strategy
        return $this->account->balance;
    }

    public function checkCanUpdateState(): bool
    {
        // TODO: implement check can update state for account leaf using the strategy
        return $this->account->checkCanUpdateState();
    }
}