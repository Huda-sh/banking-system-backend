<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Transfer;
use App\Models\Transaction;
use App\Enums\TransactionType;

class TransferSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $transfers = Transaction::where('type', TransactionType::TRANSFER)->get();

        foreach ($transfers as $transaction) {
            Transfer::create([
                'transaction_id' => $transaction->id,
                'from_account_id' => $transaction->from_account_id,
                'to_account_id' => $transaction->to_account_id
            ]);
        }

        $this->command->info('Transfers seeded successfully.');
    }
}
