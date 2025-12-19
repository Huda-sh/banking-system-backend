<?php

namespace App\Accounts\Contracts;

use App\Accounts\Contracts\AccountComponent;
use App\Models\User;

interface AccountState
{
    public function getName(): string;
    public function deposit();
    public function withdraw();
    public function transfer();

    /**
     * Transition the account component to this state
     * Performs all validation checks and creates the state record
     * 
     * @param AccountComponent $component The account component to transition
     * @param User $changedBy The user initiating the change
     * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException if transition is not allowed
     * @throws \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException if user doesn't have required role
     * @return void
     */
    public function transition(AccountComponent $component, User $changedBy): void;
}
