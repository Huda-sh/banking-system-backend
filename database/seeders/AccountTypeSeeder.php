<?php

namespace Database\Seeders;

use App\Models\AccountType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AccountTypeSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accountTypes = [
            [
                'name' => 'Savings',
                'description' => 'Personal savings account with interest calculated on balance',
                'interest_strategy' => 'App\Accounts\Strategies\SavingsInterestsStrategy',
            ],
            [
                'name' => 'Checking',
                'description' => 'Standard transactional account for everyday banking',
                'interest_strategy' => null,
            ],
            [
                'name' => 'Business',
                'description' => 'Business account for commercial entities',
                'interest_strategy' => null,
            ],
            [
                'name' => 'Personal',
                'description' => 'Standard personal banking account',
                'interest_strategy' => null,
            ],
        ];

        foreach ($accountTypes as $accountType) {
            AccountType::firstOrCreate(
                ['name' => $accountType['name']],
                $accountType
            );
        }
    }
}
