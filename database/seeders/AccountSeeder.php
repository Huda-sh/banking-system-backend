<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();

        // Create specific accounts
        $savingsAccount = Account::create([
            'account_type_id' => 1, // Assuming first account type is Savings
            'parent_account_id' => null,
            'account_number' => 'AC-0000000001',
            'balance' => 5000.00,
            'currency' => 'USD',
        ]);
        $checkingAccount = Account::create([
            'account_type_id' => 2, // Assuming second is Checking
            'parent_account_id' => null,
            'account_number' => 'AC-0000000002',
            'balance' => 3000.00,
            'currency' => 'USD',
        ]);
        $businessAccount = Account::create([
            'account_type_id' => 3, // Business
            'parent_account_id' => null,
            'account_number' => 'AC-0000000003',
            'balance' => 100000.00,
            'currency' => 'USD',
        ]);
        $jointAccount = Account::create([
            'account_type_id' => 4, // Personal
            'parent_account_id' => null,
            'account_number' => 'AC-0000000004',
            'balance' => 0.00,
            'currency' => 'USD',
        ]);
        $businessAccount2 = Account::create([
            'account_type_id' => 3, // Business
            'parent_account_id' => null,
            'account_number' => 'AC-0000000003',
            'balance' => 100000.00,
            'currency' => 'USD',
        ]);
        // Attach accounts to users
        if ($users->count() > 0) {
            $firstUser = $users[0];
            $firstUser->accounts()->attach($savingsAccount->id, ['is_owner' => true]);
            $firstUser->accounts()->attach($checkingAccount->id, ['is_owner' => true]);

            if ($users->count() > 1) {
                $secondUser = $users[1];
                $secondUser->accounts()->attach($businessAccount->id, ['is_owner' => true]);
            }

            if ($users->count() > 2) {
                $thirdUser = $users[2];
                $thirdUser->accounts()->attach($jointAccount->id, ['is_owner' => true]);
            }
            if ($users->count() > 3) {
                $fourthUser = $users[3];
                $fourthUser->accounts()->attach($businessAccount2->id, ['is_owner' => true]);
            }

        }
    }
}
