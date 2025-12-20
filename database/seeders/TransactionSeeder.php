<?php

namespace Database\Seeders;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Account;
use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use App\Enums\Direction;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TransactionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();
        $accounts = Account::all();

        if ($users->isEmpty() || $accounts->isEmpty()) {
            return; // Skip if no users or accounts
        }

        $transactions = [
            // Deposits (credit to target, source set to target for external)
            [
                'reference_number' => 'TXN-000001',
                'description' => 'Initial deposit to savings account',
                'source_account_id' => $accounts->where('account_number', 'AC-0000000001')->first()?->id,
                'target_account_id' => $accounts->where('account_number', 'AC-0000000001')->first()?->id,
                'amount' => 1000.00,
                'currency' => 'USD',
                'type' => TransactionType::DEPOSIT->value,
                'status' => TransactionStatus::COMPLETED->value,
                'direction' => Direction::CREDIT->value,
                'initiated_by' => $users->first()->id,
                'processed_by' => $users->first()->id,
            ],
            [
                'reference_number' => 'TXN-000002',
                'description' => 'Deposit to checking account',
                'source_account_id' => $accounts->where('account_number', 'AC-0000000002')->first()?->id,
                'target_account_id' => $accounts->where('account_number', 'AC-0000000002')->first()?->id,
                'amount' => 500.00,
                'currency' => 'USD',
                'type' => TransactionType::DEPOSIT->value,
                'status' => TransactionStatus::COMPLETED->value,
                'direction' => Direction::CREDIT->value,
                'initiated_by' => $users->skip(1)->first()->id,
                'processed_by' => $users->skip(1)->first()->id,
            ],
            // Withdrawals (debit from source, target set to source for external)
            [
                'reference_number' => 'TXN-000003',
                'description' => 'ATM withdrawal',
                'source_account_id' => $accounts->where('account_number', 'AC-0000000001')->first()?->id,
                'target_account_id' => $accounts->where('account_number', 'AC-0000000001')->first()?->id,
                'amount' => 200.00,
                'currency' => 'USD',
                'type' => TransactionType::WITHDRAWAL->value,
                'status' => TransactionStatus::COMPLETED->value,
                'direction' => Direction::DEBIT->value,
                'initiated_by' => $users->first()->id,
                'processed_by' => $users->first()->id,
            ],
            [
                'reference_number' => 'TXN-000004',
                'description' => 'Cash withdrawal',
                'source_account_id' => $accounts->where('account_number', 'AC-0000000002')->first()?->id,
                'target_account_id' => $accounts->where('account_number', 'AC-0000000002')->first()?->id,
                'amount' => 100.00,
                'currency' => 'USD',
                'type' => TransactionType::WITHDRAWAL->value,
                'status' => TransactionStatus::PENDING_APPROVAL->value,
                'direction' => Direction::DEBIT->value,
                'initiated_by' => $users->skip(1)->first()->id,
                'processed_by' => null,
            ],
            // Transfers
            [
                'reference_number' => 'TXN-000005',
                'description' => 'Transfer from savings to checking',
                'source_account_id' => $accounts->where('account_number', 'AC-0000000001')->first()?->id,
                'target_account_id' => $accounts->where('account_number', 'AC-0000000002')->first()?->id,
                'amount' => 300.00,
                'currency' => 'USD',
                'type' => TransactionType::TRANSFER->value,
                'status' => TransactionStatus::COMPLETED->value,
                'direction' => Direction::DEBIT->value, // From source perspective
                'initiated_by' => $users->first()->id,
                'processed_by' => $users->first()->id,
            ],
            [
                'reference_number' => 'TXN-000006',
                'description' => 'Transfer to business account',
                'source_account_id' => $accounts->where('account_number', 'AC-0000000002')->first()?->id,
                'target_account_id' => $accounts->where('account_number', 'AC-0000000003')->first()?->id,
                'amount' => 2000.00,
                'currency' => 'USD',
                'type' => TransactionType::TRANSFER->value,
                'status' => TransactionStatus::APPROVAL_NOT_REQUIRED->value,
                'direction' => Direction::DEBIT->value,
                'initiated_by' => $users->skip(1)->first()->id,
                'processed_by' => null,
            ],
            // Rejected transaction
            [
                'reference_number' => 'TXN-000007',
                'description' => 'Large withdrawal rejected',
                'source_account_id' => $accounts->where('account_number', 'AC-0000000001')->first()?->id,
                'target_account_id' => $accounts->where('account_number', 'AC-0000000001')->first()?->id,
                'amount' => 10000.00,
                'currency' => 'USD',
                'type' => TransactionType::WITHDRAWAL->value,
                'status' => TransactionStatus::REJECTED->value,
                'direction' => Direction::DEBIT->value,
                'initiated_by' => $users->first()->id,
                'processed_by' => $users->skip(2)->first()->id,
            ],
        ];

        foreach ($transactions as $transactionData) {
            Transaction::create($transactionData);
        }
    }
}
