<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use App\Models\ScheduledTransaction;

class ScheduledTransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        $baseData = [
            'id' => $this->id,
            'frequency' => $this->frequency,
            'frequency_label' => $this->getScheduleDetails()['frequency_label'],
            'next_execution' => $this->next_execution ? $this->next_execution->toISOString() : null,
            'execution_count' => $this->execution_count,
            'max_executions' => $this->max_executions,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at ? $this->created_at->toISOString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            'remaining_executions' => $this->getScheduleDetails()['remaining_executions'],
            'estimated_completion_date' => $this->getEstimatedCompletionDate(),
            'can_be_executed' => $this->canBeExecuted(),
            'can_be_cancelled' => $this->is_active,
            'can_be_reactivated' => !$this->is_active && $this->execution_count < ($this->max_executions ?? PHP_INT_MAX)
        ];

        // Add transaction relationship if included
        if ($this->relationLoaded('transaction')) {
            $baseData['transaction'] = new TransactionResource($this->transaction);
        }

        return $baseData;
    }

    private function getEstimatedCompletionDate(): ?string
    {
        if (!$this->max_executions || $this->execution_count >= $this->max_executions) {
            return null;
        }

        $remaining = $this->max_executions - $this->execution_count;
        $nextDate = $this->next_execution ? clone $this->next_execution : now();

        for ($i = 0; $i < $remaining - 1; $i++) {
            $nextDate = match($this->frequency) {
                'daily' => $nextDate->addDay(),
                'weekly' => $nextDate->addWeek(),
                'monthly' => $nextDate->addMonth(),
                'yearly' => $nextDate->addYear(),
                default => $nextDate->addDay()
            };
        }

        return $nextDate->toISOString();
    }

    public function with($request): array
    {
        return [
            'meta' => [
                'api_version' => '1.0',
                'timestamp' => Carbon::now()->toISOString()
            ]
        ];
    }
}
