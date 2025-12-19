<?php

namespace App\Exceptions;

use App\Models\User;
use Exception;
use Illuminate\Http\Response;

class DailyLimitExceededException extends TransactionException
{
    public function __construct(
        public User $user,
        public float $dailyLimit,
        public float $usedAmount,
        public float $remainingAmount,
        public float $attemptedAmount,
        string $message = '',
        int $code = 0,
        Exception $previous = null
    ) {
        $message = $message ?: sprintf(
            'Daily transaction limit exceeded. Limit: %.2f, Used: %.2f, Remaining: %.2f, Attempted: %.2f',
            $dailyLimit,
            $usedAmount,
            $remainingAmount,
            $attemptedAmount
        );

        parent::__construct($message, $code, $previous);
    }

    public function render($request): Response
    {
        return response()->json([
            'error' => 'Daily Limit Exceeded',
            'message' => $this->getMessage(),
            'user_id' => $this->user->id,
            'daily_limit' => $this->dailyLimit,
            'used_amount' => $this->usedAmount,
            'remaining_amount' => $this->remainingAmount,
            'attempted_amount' => $this->attemptedAmount,
            'currency' => 'USD', // Could be dynamic
            'reset_time' => now()->endOfDay()->format('Y-m-d H:i:s')
        ], 403);
    }

    public function getDetails(): array
    {
        $details = parent::getDetails();

        $details['limit_data'] = [
            'daily_limit' => $this->dailyLimit,
            'used_amount' => $this->usedAmount,
            'remaining_amount' => $this->remainingAmount,
            'attempted_amount' => $this->attemptedAmount,
            'currency' => 'USD',
            'reset_timestamp' => now()->endOfDay()->timestamp,
            'reset_time_iso' => now()->endOfDay()->format('c')
        ];

        $details['user_data'] = [
            'user_id' => $this->user->id,
            'user_name' => $this->user->full_name,
            'email' => $this->user->email,
            'role' => $this->user->roles->pluck('name')->implode(', '),
            'account_count' => $this->user->accounts()->count()
        ];

        return $details;
    }
}
