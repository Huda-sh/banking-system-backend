<?php

namespace App\Accounts\Factories;

use App\Models\Account;

class AccountFactory {
    public static function create(array $data): Account
    {
        return new Account($data);
    }
}