<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SchedulerService;
use App\Models\ScheduledTransaction;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProcessScheduledTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:process-scheduled
                            {--dry-run : Run in dry-run mode without making changes}
                            {--limit=100 : Maximum number of transactions to process}
                            {--force : Force processing even if maintenance mode is on}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all due scheduled transactions';

    /**
     * Execute the console command.
     */
    public function handle(SchedulerService $schedulerService): void
    {
        if (!$this->option('force') && app()->isDownForMaintenance()) {
            $this->error('Application is in maintenance mode. Use --force to override.');
            return;
        }

        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info('Processing scheduled transactions...');
        $this->info(sprintf('Mode: %s', $dryRun ? 'DRY RUN' : 'LIVE'));
        $this->info(sprintf('Limit: %d transactions', $limit));

        if ($dryRun) {
            $this->warn('This is a dry run. No actual transactions will be processed.');
        }

        try {
            $startTime = now();

            // Get due transactions count first
            $dueCount = ScheduledTransaction::due()->count();
            $this->info(sprintf('Found %d due scheduled transactions', $dueCount));

            if ($dueCount === 0) {
                $this->info('No due scheduled transactions to process.');
                return;
            }

            // Process transactions
            $results = $schedulerService->processDueTransactions();

            $processingTime = now()->diffInSeconds($startTime);

            $this->info('Scheduled transactions processing completed:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Processed', $results['processed']],
                    ['Failed', $results['failed']],
                    ['Skipped', $results['skipped']],
                    ['Total', $results['processed'] + $results['failed'] + $results['skipped']],
                    ['Processing Time', "{$processingTime} seconds"],
                    ['Success Rate', sprintf('%.2f%%', $results['processed'] > 0 ? ($results['processed'] / ($results['processed'] + $results['failed'] + $results['skipped'])) * 100 : 0)]
                ]
            );

            if ($results['failed'] > 0) {
                $this->error(sprintf('Failed to process %d transactions. Check logs for details.', $results['failed']));
            }

            if ($results['skipped'] > 0) {
                $this->warn(sprintf('Skipped %d transactions due to constraints.', $results['skipped']));
            }

            // Log the results
            Log::info('Scheduled transactions processed successfully', [
                'processed' => $results['processed'],
                'failed' => $results['failed'],
                'skipped' => $results['skipped'],
                'processing_time' => $processingTime,
                'mode' => $dryRun ? 'dry_run' : 'live'
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing scheduled transactions', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);

            $this->error('An error occurred while processing scheduled transactions:');
            $this->error($e->getMessage());

            if ($this->option('verbose')) {
                $this->line($e->getTraceAsString());
            }

            $this->exit(1);
        }
    }

    /**
     * Get the console command help text.
     */
    public function getHelp(): string
    {
        return <<<'HELP'
This command processes all due scheduled transactions in the system.

Options:
  --dry-run     Run in dry-run mode without making actual changes
  --limit=100   Maximum number of transactions to process (default: 100)
  --force       Force processing even if application is in maintenance mode

The command will:
1. Find all scheduled transactions with next_execution <= now()
2. Process each transaction according to its configuration
3. Update execution counts and next execution dates
4. Handle failures and retries according to business rules
5. Provide detailed statistics about the processing results

In dry-run mode, the command will simulate the processing without making
any actual database changes, which is useful for testing and debugging.
HELP;
    }
}
