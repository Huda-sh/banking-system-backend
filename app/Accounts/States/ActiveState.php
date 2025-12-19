<?php

namespace App\Accounts\States;

use App\Accounts\Contracts\AccountComponent;
use App\Accounts\Contracts\AccountState;
use App\Accounts\Exceptions\AccountAuthorizationException;
use App\Accounts\Exceptions\AccountTransitionException;
use App\Models\User;
use App\Models\AccountState as AccountStateModel;

class ActiveState implements AccountState
{
    public function getName(): string
    {
        return 'active';
    }

    public function deposit()
    {
        // TODO: implement deposit for active state
    }

    public function withdraw()
    {
        // TODO: implement withdraw for active state
    }

    public function transfer()
    {
        // TODO: implement transfer for active state
    }

    public function transition(AccountComponent $component, User $changedBy): void
    {
        $account = $component->account;
        $currentState = $account->currentState;

        // Cannot transition to active from closed state
        if ($currentState && $currentState->state === 'closed') {
            throw new AccountTransitionException(
                'Cannot reactivate a closed account. Closed accounts cannot be transitioned back to active state.'
            );
        }

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
