<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\User;
use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use App\Enums\Direction;

class TransactionsSeeder extends Seeder
{
    public function run()
    {
        $customer1 = User::where('email', 'customer1@example.com')->first();
        $customer2 = User::where('email', 'customer2@example.com')->first();
        $admin = User::where('email', 'admin@example.com')->first();

        $savingsAccount = Account::where('account_number', 'AC-0000000001')->first();
        $checkingAccount = Account::where('account_number', 'AC-0000000002')->first();
        $businessAccount = Account::where('account_number', 'AC-0000000003')->first();
        $jointAccount = Account::where('account_number', 'AC-0000000004')->first();

        // Ensure required users and accounts exist before seeding transactions
        if (!$customer1 || !$customer2 || !$admin || !$savingsAccount || !$checkingAccount || !$businessAccount || !$jointAccount) {
            $this->command->info('TransactionsSeeder: required users or accounts not found, skipping transaction creation.');
            return;
        }

        // Create deposits
        Transaction::create([
            'from_account_id' => null,
            'to_account_id' => $savingsAccount->id,
            'amount' => 5000.00,
            'currency' => 'USD',
            'type' => TransactionType::DEPOSIT,
            'status' => TransactionStatus::COMPLETED,
            'direction' => Direction::INCOMING->value,
            'fee' => 0.00,
            'initiated_by' => $customer1->id,
            'processed_by' => $customer1->id,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'description' => 'Initial deposit'
        ]);

        Transaction::create([
            'from_account_id' => null,
            'to_account_id' => $checkingAccount->id,
            'amount' => 3000.00,
            'currency' => 'USD',
            'type' => TransactionType::DEPOSIT,
            'status' => TransactionStatus::COMPLETED,
            'direction' => Direction::INCOMING->value,
            'fee' => 0.00,
            'initiated_by' => $customer1->id,
            'processed_by' => $customer1->id,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'description' => 'Monthly salary'
        ]);

        // Create transfers
        Transaction::create([
            'from_account_id' => $checkingAccount->id,
            'to_account_id' => $savingsAccount->id,
            'amount' => 1000.00,
            'currency' => 'USD',
            'type' => TransactionType::TRANSFER,
            'status' => TransactionStatus::COMPLETED,
            'direction' => Direction::OUTGOING->value,
            'fee' => 0.00,
            'initiated_by' => $customer1->id,
            'processed_by' => $customer1->id,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'description' => 'Savings transfer'
        ]);

        Transaction::create([
            'from_account_id' => $savingsAccount->id,
            'to_account_id' => $jointAccount->id,
            'amount' => 2000.00,
            'currency' => 'USD',
            'type' => TransactionType::TRANSFER,
            'status' => TransactionStatus::COMPLETED,
            'direction' => Direction::OUTGOING->value,
            'fee' => 0.00,
            'initiated_by' => $customer1->id,
            'processed_by' => $customer1->id,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'description' => 'Joint account funding'
        ]);

        // Create withdrawal
        Transaction::create([
            'from_account_id' => $checkingAccount->id,
            'to_account_id' => null,
            'amount' => 500.00,
            'currency' => 'USD',
            'type' => TransactionType::WITHDRAWAL,
            'status' => TransactionStatus::COMPLETED,
            'direction' => Direction::OUTGOING->value,
            'fee' => 2.50,
            'initiated_by' => $customer1->id,
            'processed_by' => $customer1->id,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'description' => 'ATM withdrawal'
        ]);

        // Create large transfer requiring approval
        Transaction::create([
            'from_account_id' => $businessAccount->id,
            'to_account_id' => $savingsAccount->id,
            'amount' => 75000.00,
            'currency' => 'USD',
            'type' => TransactionType::TRANSFER,
            'status' => TransactionStatus::PENDING_APPROVAL,
            'direction' => Direction::OUTGOING->value,
            'fee' => 750.00,
            'initiated_by' => $customer2->id,
            'description' => 'Large business transfer'
        ]);

        // Create failed transaction
        Transaction::create([
            'from_account_id' => $checkingAccount->id,
            'to_account_id' => $businessAccount->id,
            'amount' => 100000.00,
            'currency' => 'USD',
            'type' => TransactionType::TRANSFER,
            'status' => TransactionStatus::FAILED,
            'direction' => Direction::OUTGOING->value,
            'fee' => 0.00,
            'initiated_by' => $customer1->id,
            'description' => 'Insufficient balance',
            'metadata' => [
                'error' => 'Insufficient balance',
                'error_class' => 'InsufficientBalanceException'
            ]
        ]);
    }
}
