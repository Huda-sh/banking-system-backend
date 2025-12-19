<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Auth
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
        ]);

        // Accounts
        $this->call([
            AccountTypeSeeder::class,
        ]);

        // Transactions
        $this->call([
            TransactionsSeeder::class,
            TransactionApprovalsSeeder::class,
            ScheduledTransactionsSeeder::class,
        ]);
    }
}
