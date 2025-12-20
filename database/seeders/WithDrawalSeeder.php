<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\WithDrawal;
use App\Models\Transaction;
use App\Enums\TransactionType;

class WithDrawalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $withdrawals = Transaction::where('type', TransactionType::WITHDRAWAL)->get();

        foreach ($withdrawals as $transaction) {
            WithDrawal::create([
                'transaction_id' => $transaction->id,
                'method' => 'atm'
            ]);
        }

        $this->command->info('Withdrawals seeded successfully.');
    }
}
