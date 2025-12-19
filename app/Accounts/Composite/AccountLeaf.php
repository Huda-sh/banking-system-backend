<?php

namespace App\Accounts\Composite;

use App\Accounts\Contracts\AccountComponent;
use App\Accounts\Contracts\AccountState;
use App\Models\Account;
use App\Models\User;

class AccountLeaf implements AccountComponent
{
    public function __construct(public readonly Account $account) {}

    public function getBalance(): float
    {
        // Leaf account balance is calculated from transactions
        // For now, return the stored balance (transactions to be implemented)
        return (float) $this->account->balance;
    }

    public function applyState(AccountState $state, User $changedBy): AccountComponent
    {
        // Transition leaf to new state (validates and creates state record)
        $state->transition($this, $changedBy);

        // Reload relationships
        $this->account->load([
            'accountType',
            'users',
            'currentState',
            'features'
        ]);

        return $this;
    }
}
