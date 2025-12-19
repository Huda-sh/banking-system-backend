<?php

namespace App\Accounts\Contracts;

interface AccountState
{
    public function deposit();
    public function withdraw();
    public function transfer();
}