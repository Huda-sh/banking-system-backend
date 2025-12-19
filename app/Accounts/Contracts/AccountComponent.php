<?php

namespace App\Accounts\Contracts;

interface AccountComponent
{
    public function getBalance(): float;
    public function checkCanUpdateState(): bool;
}