<?php

namespace App\Accounts\Factories;

use App\Accounts\Contracts\AccountState;
use App\Accounts\Exceptions\InvalidAccountStateException;
use App\Accounts\States\ActiveState;
use App\Accounts\States\ClosedState;
use App\Accounts\States\FrozenState;
use App\Accounts\States\SuspendedState;

class AccountStateFactory
{
    public static function create(string $stateName): AccountState
    {
        return match (strtolower($stateName)) {
            'active' => new ActiveState(),
            'frozen' => new FrozenState(),
            'suspended' => new SuspendedState(),
            'closed' => new ClosedState(),
            default => throw new InvalidAccountStateException("Invalid state: {$stateName}"),
        };
    }
}
