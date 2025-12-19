<?php

namespace App\Accounts\Composite;

use App\Accounts\Contracts\AccountComponent;
use App\Accounts\Contracts\AccountState;
use App\Models\Account;
use App\Models\User;

class AccountGroup implements AccountComponent
{
    public function __construct(public readonly Account $account) {}

    public function getBalance(): float
    {
        // Group balance is the sum of all children account balances
        $totalBalance = (float) $this->account->balance;

        foreach ($this->account->childrenAccounts as $child) {
            $totalBalance += (float) $child->balance;
        }

        return $totalBalance;
    }

    public function applyState(AccountState $state, User $changedBy): AccountComponent
    {
        // Transition group to new state (validates and creates state record)
        $state->transition($this, $changedBy);

        // Cascade state change to all children using Composite pattern
        $children = $this->account->childrenAccounts;
        foreach ($children as $child) {
            $childLeaf = new AccountLeaf($child);
            $childLeaf->applyState($state, $changedBy);
        }

        // Reload relationships
        $this->account->load([
            'accountType',
            'users',
            'currentState',
            'childrenAccounts.accountType',
            'childrenAccounts.users',
            'childrenAccounts.currentState',
            'features'
        ]);

        return $this;
    }
}
