<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Enums\TransactionType;

class DepositSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $deposits = Transaction::where('type', TransactionType::DEPOSIT)->get();

        foreach ($deposits as $transaction) {
            Deposit::create([
                'transaction_id' => $transaction->id,
                'method' => 'bank_transfer'
            ]);
        }

        $this->command->info('Deposits seeded successfully.');
    }
}
