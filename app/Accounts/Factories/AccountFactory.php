<?php

namespace App\Accounts\Factories;

use App\Models\Account;

class AccountFactory
{
    public static function create(array $data): Account
    {
        return Account::create($data);
    }

    public static function generateAccountNumber(bool $isGroup = false): string
    {
        $nextId = Account::max('id') ? Account::max('id') + 1 : 1;
        $prefix = $isGroup ? 'G-AC-' : 'AC-';
        return $prefix . str_pad((string) $nextId, 10, '0', STR_PAD_LEFT);
    }
}
