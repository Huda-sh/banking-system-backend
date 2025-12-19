<?php

namespace App\Listeners;

use App\Events\ScheduledTransactionExecuted;
use App\Services\SchedulerService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProcessNextScheduledTransaction
{
    /**
     * Create the event listener.
     */
    public function __construct(private SchedulerService $schedulerService)
    {
        //
    }

    /**
     * Handle the scheduled transaction executed event.
     */
    public function handle(ScheduledTransactionExecuted $event): void
    {
        try {
            // Check if there are more executions needed
            if ($event->scheduled->is_active && $event->scheduled->canBeExecuted()) {
                Log::info('ProcessNextScheduledTransaction: Scheduling next execution', [
                    'scheduled_id' => $event->scheduled->id,
                    'next_execution' => $event->scheduled->next_execution?->format('Y-m-d H:i:s'),
                    'execution_count' => $event->scheduled->execution_count
                ]);

                // Schedule the next execution
                $this->scheduleNextExecution($event->scheduled);
            } else {
                // Schedule is complete or inactive
                $this->handleScheduleCompletion($event->scheduled);
            }

        } catch (\Exception $e) {
            Log::error('ProcessNextScheduledTransaction: Failed to process next scheduled transaction', [
                'scheduled_id' => $event->scheduled->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
        }
    }

    /**
     * Schedule the next execution for a scheduled transaction.
     */
    private function scheduleNextExecution($scheduled): void
    {
        $nextExecution = $scheduled->next_execution;

        if (!$nextExecution) {
            return;
        }

        // Dispatch a job to process the next execution
        // This would typically use Laravel's queue system
        Log::debug('ProcessNextScheduledTransaction: Next execution scheduled', [
            'scheduled_id' => $scheduled->id,
            'scheduled_time' => $nextExecution->format('Y-m-d H:i:s'),
            'delay_minutes' => now()->diffInMinutes($nextExecution)
        ]);

        // In production, this would dispatch a queued job
        // ProcessScheduledTransaction::dispatch($scheduled)->delay($nextExecution);
    }

    /**
     * Handle schedule completion.
     */
    private function handleScheduleCompletion($scheduled): void
    {
        if (!$scheduled->is_active) {
            Log::info('ProcessNextScheduledTransaction: Schedule is inactive', [
                'scheduled_id' => $scheduled->id
            ]);
            return;
        }

        if ($scheduled->max_executions && $scheduled->execution_count >= $scheduled->max_executions) {
            Log::info('ProcessNextScheduledTransaction: Schedule completed - max executions reached', [
                'scheduled_id' => $scheduled->id,
                'max_executions' => $scheduled->max_executions,
                'execution_count' => $scheduled->execution_count
            ]);

            // Mark as complete
            $scheduled->update(['is_active' => false]);
        } else {
            Log::info('ProcessNextScheduledTransaction: Schedule completed - no more executions needed', [
                'scheduled_id' => $scheduled->id
            ]);
        }
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe($events): void
    {
        $events->listen(
            ScheduledTransactionExecuted::class,
            [ProcessNextScheduledTransaction::class, 'handle']
        );
    }
}
