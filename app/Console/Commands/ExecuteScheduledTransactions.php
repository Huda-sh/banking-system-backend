<?php

namespace App\Console\Commands;

use App\Models\RecurringTransaction;
use App\Models\ScheduledTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
class ExecuteScheduledTransactions extends Command
{
    protected $signature = 'transactions:execute';
    protected $description = 'Execute all pending scheduled and recurring transactions';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Starting transaction execution...');

        // 1. تنفيذ المعاملات المجدولة
        $scheduled = ScheduledTransaction::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        $executedScheduled = 0;
        foreach ($scheduled as $transaction) {
            try {
                // محاكاة التنفيذ
                $transaction->update([
                    'status' => 'executed',
                ]);

                $executedScheduled++;
                $this->info("✓ Executed scheduled transaction #{$transaction->id}");
            } catch (\Exception $e) {
                $transaction->update([
                    'status' => 'failed',
                ]);
                $this->error("✗ Failed to execute scheduled transaction #{$transaction->id}: " . $e->getMessage());
                Log::error('Scheduled transaction execution failed', [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 2. تنفيذ المعاملات المتكررة
        $recurring = RecurringTransaction::where('active', true)
            ->get();

        $executedRecurring = 0;
        foreach ($recurring as $transaction) {
            if ($transaction->isActive()) {
                try {
                    // محاكاة التنفيذ
                    $newTransaction = \App\Models\Transaction::create([
                        'type' => $transaction->type,
                        'source_account_id' => $transaction->account_id,
                        'target_account_id' => $transaction->target_account_id,
                        'amount' => $transaction->amount,
                        'currency' => 'USD', // يمكنك تعديل هذا حسب الحاجة
                        'description' => 'Recurring transaction',
                        'status' => 'completed',
                        'direction' => 'debit',
                        'initiated_by' => $transaction->created_by,
                        'processed_by' => $transaction->created_by,
                    ]);

                    $executedRecurring++;
                    $this->info("✓ Executed recurring transaction #{$transaction->id}");

                    // تحديث تاريخ التنفيذ الأخير
                    $transaction->last_executed_at = now();
                    $transaction->save();
                } catch (\Exception $e) {
                    Log::error('Recurring transaction execution failed', [
                        'transaction_id' => $transaction->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->info("Execution completed. Scheduled: {$executedScheduled}, Recurring: {$executedRecurring}");
        return 0;
    }
}
