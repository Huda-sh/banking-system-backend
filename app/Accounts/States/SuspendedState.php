<?php

namespace App\Accounts\States;

use App\Accounts\Contracts\AccountComponent;
use App\Accounts\Contracts\AccountState;
use App\Accounts\Exceptions\AccountAuthorizationException;
use App\Models\User;
use App\Models\AccountState as AccountStateModel;

class SuspendedState implements AccountState
{
    public function getName(): string
    {
        return 'suspended';
    }

    public function deposit()
    {
        // TODO: implement deposit for suspended state
    }

    public function withdraw()
    {
        // TODO: implement withdraw for suspended state
    }

    public function transfer()
    {
        // TODO: implement transfer for suspended state
    }

    public function transition(AccountComponent $component, User $changedBy): void
    {
        // Only Admin can transition TO suspended state
        $userRoles = $changedBy->roles->pluck('name')->toArray();
        if (!in_array('Admin', $userRoles)) {
            throw new AccountAuthorizationException(
                'Only Admin users can transition accounts to suspended state.'
            );
        }

        $account = $component->account;
        $currentState = $account->currentState;

        // Only Admin can transition FROM suspended state
        if ($currentState && $currentState->state === 'suspended') {
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
