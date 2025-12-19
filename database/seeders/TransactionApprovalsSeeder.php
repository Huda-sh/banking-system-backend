<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TransactionApproval;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalLevel;

class TransactionApprovalsSeeder extends Seeder
{
    public function run()
    {
        $manager = User::where('email', 'manager@example.com')->first();
        $admin = User::where('email', 'admin@example.com')->first();

        // Get the large transfer transaction that needs approval
        $largeTransfer = Transaction::where('description', 'Large business transfer')->first();

        if ($largeTransfer) {
            // Create approval for manager
            TransactionApproval::create([
                'transaction_id' => $largeTransfer->id,
                'approver_id' => $manager->id,
                'level' => ApprovalLevel::MANAGER,
                'status' => ApprovalStatus::PENDING,
                'notes' => 'Manager approval required for large transfer'
            ]);

            // Create approval for admin
            TransactionApproval::create([
                'transaction_id' => $largeTransfer->id,
                'approver_id' => $admin->id,
                'level' => ApprovalLevel::ADMIN,
                'status' => ApprovalStatus::PENDING,
                'notes' => 'Admin approval required for large transfer'
            ]);
        }
    }
}
