<?php

namespace App\Accounts\Strategies;

use App\Accounts\Contracts\InterestStrategy;

class SavingsInterestsStrategy implements InterestStrategy
{
    public function calculateInterest(float $balance): float
    {
        return $balance * 0.01;
    }
}