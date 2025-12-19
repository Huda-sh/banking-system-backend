<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ScheduledTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Account;
use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use App\Enums\Direction;
use Carbon\Carbon;

class ScheduledTransactionsSeeder extends Seeder
{
    public function run()
    {
        $admin = User::where('email', 'admin@example.com')->first();
        $customer1 = User::where('email', 'customer1@example.com')->first();

        $checkingAccount = Account::where('account_number', 'AC-0000000002')->first();
        $savingsAccount = Account::where('account_number', 'AC-0000000001')->first();

        if (!$admin || !$customer1 || !$checkingAccount || !$savingsAccount) {
            $this->command->info('ScheduledTransactionsSeeder: required users or accounts not found, skipping.');
            return;
        }

        // Create monthly savings transfer
        $monthlyTransfer = Transaction::create([
            'from_account_id' => $checkingAccount->id,
            'to_account_id' => $savingsAccount->id,
            'amount' => 500.00,
            'currency' => 'USD',
            'type' => TransactionType::SCHEDULED,
            'status' => TransactionStatus::SCHEDULED,
            'direction' => Direction::OUTGOING->value,
            'fee' => 0.00,
            'initiated_by' => $customer1->id,
            'description' => 'Monthly savings transfer'
        ]);

        ScheduledTransaction::create([
            'transaction_id' => $monthlyTransfer->id,
            'frequency' => 'monthly',
            'next_execution' => now()->addMonth(),
            'execution_count' => 0,
            'max_executions' => 12,
            'is_active' => true
        ]);

        // Create weekly bill payment
        $weeklyBill = Transaction::create([
            'from_account_id' => $checkingAccount->id,
            'to_account_id' => $savingsAccount->id, // This would be a vendor account in reality
            'amount' => 100.00,
            'currency' => 'USD',
            'type' => TransactionType::SCHEDULED,
            'status' => TransactionStatus::SCHEDULED,
            'direction' => Direction::OUTGOING->value,
            'fee' => 0.00,
            'initiated_by' => $customer1->id,
            'description' => 'Weekly utility bill'
        ]);

        ScheduledTransaction::create([
            'transaction_id' => $weeklyBill->id,
            'frequency' => 'weekly',
            'next_execution' => now()->addWeek(),
            'execution_count' => 0,
            'max_executions' => 52,
            'is_active' => true
        ]);
    }
}
