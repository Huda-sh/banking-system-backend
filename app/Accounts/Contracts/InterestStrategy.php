<?php 

namespace App\Accounts\Contracts;

interface InterestStrategy
{
    public function calculateInterest(float $balance): float;
}