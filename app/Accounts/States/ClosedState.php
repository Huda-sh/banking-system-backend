<?php

namespace App\Accounts\States;

use App\Accounts\Contracts\AccountComponent;
use App\Accounts\Contracts\AccountState;
use App\Accounts\Exceptions\AccountAuthorizationException;
use App\Accounts\Exceptions\AccountTransitionException;
use App\Models\User;
use App\Models\AccountState as AccountStateModel;

class ClosedState implements AccountState
{
    public function getName(): string
    {
        return 'closed';
    }

    public function deposit()
    {
        // TODO: implement deposit for closed state
    }

    public function withdraw()
    {
        // TODO: implement withdraw for closed state
    }

    public function transfer()
    {
        // TODO: implement transfer for closed state
    }

    public function transition(AccountComponent $component, User $changedBy): void
    {
        $account = $component->account;

        // Account balance must be 0 before closing
        $balance = (float) $account->balance;
        if ($balance !== 0.00) {
            throw new AccountTransitionException(
                'Account balance must be 0 before closing. Current balance: ' . number_format($balance, 2)
            );
        }

        $currentState = $account->currentState;

        // Check if transitioning FROM suspended (requires Admin)
        if ($currentState && $currentState->state === 'suspended') {
            $userRoles = $changedBy->roles->pluck('name')->toArray();
            if (!in_array('Admin', $userRoles)) {
                throw new AccountAuthorizationException(
                    'Only Admin users can transition accounts from suspended state.'
                );
            }
        }

        // Create state record
        AccountStateModel::create([
            'account_id' => $account->id,
            'state' => $this->getName(),
            'changed_by' => $changedBy->id,
        ]);
    }
}
