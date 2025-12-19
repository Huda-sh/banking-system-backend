<?php

namespace App\Accounts\Contracts;

use App\Accounts\Contracts\AccountState;
use App\Models\User;

interface AccountComponent
{
    public function getBalance(): float;

    /**
     * Apply state change to this component
     * 
     * @param AccountState $state The state to apply
     * @param User $changedBy The user initiating the change
     * @return AccountComponent The component after state change
     */
    public function applyState(AccountState $state, User $changedBy): AccountComponent;
}
