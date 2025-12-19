<?php

namespace App\Exceptions;

use App\Models\Account;
use Exception;
use Illuminate\Http\Response;

class InsufficientBalanceException extends TransactionException
{
    public function __construct(
        public float $requiredAmount,
        public float $availableBalance,
        public ?Account $account = null,
        string $message = '',
        int $code = 0,
        Exception $previous = null
    ) {
        $message = $message ?: sprintf(
            'Insufficient balance. Available: %.2f, Required: %.2f',
            $availableBalance,
            $requiredAmount
        );

        parent::__construct($message, $code, $previous);
    }

    public function render($request): Response
    {
        return response()->json([
            'error' => 'Insufficient Balance',
            'message' => $this->getMessage(),
            'available_balance' => $this->availableBalance,
            'required_amount' => $this->requiredAmount,
            'currency' => $this->account?->currency ?? 'USD',
            'account_id' => $this->account?->id,
            'account_number' => $this->account?->account_number
        ], 403);
    }

    public function getDetails(): array
    {
        $details = parent::getDetails();

        $details['balance_data'] = [
            'required_amount' => $this->requiredAmount,
            'available_balance' => $this->availableBalance,
            'shortfall' => $this->requiredAmount - $this->availableBalance,
            'currency' => $this->account?->currency ?? 'USD'
        ];

        if ($this->account) {
            $details['account_data'] = [
                'account_id' => $this->account->id,
                'account_number' => $this->account->account_number,
                'account_type' => $this->account->accountType?->name,
                'state' => $this->account->currentState->state
            ];
        }

        return $details;
    }
}
