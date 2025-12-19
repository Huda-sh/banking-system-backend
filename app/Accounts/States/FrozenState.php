<?php

namespace App\Accounts\States;

use App\Accounts\Contracts\AccountComponent;
use App\Accounts\Contracts\AccountState;
use App\Accounts\Exceptions\AccountAuthorizationException;
use App\Models\User;
use App\Models\AccountState as AccountStateModel;

class FrozenState implements AccountState
{
    public function getName(): string
    {
        return 'frozen';
    }

    public function deposit()
    {
        // TODO: implement deposit for frozen state
    }

    public function withdraw()
    {
        // TODO: implement withdraw for frozen state
    }

    public function transfer()
    {
        // TODO: implement transfer for frozen state
    }

    public function transition(AccountComponent $component, User $changedBy): void
    {
        $account = $component->account;
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
