<?php

namespace App\Exceptions;

use App\Models\Account;
use App\Enums\AccountState;
use Exception;
use Illuminate\Http\Response;

class AccountStateException extends TransactionException
{
    public function __construct(
        public Account $account,
        public AccountState $currentState,
        public string $requiredState = '',
        string $message = '',
        int $code = 0,
        Exception $previous = null
    ) {
        $message = $message ?: sprintf(
            'Account %s is in state "%s" but requires state "%s" for this operation',
            $account->account_number,
            $currentState->value,
            $requiredState
        );

        parent::__construct($message, $code, $previous);
    }

    public function render($request): Response
    {
        return response()->json([
            'error' => 'Account State Error',
            'message' => $this->getMessage(),
            'account_id' => $this->account->id,
            'account_number' => $this->account->account_number,
            'current_state' => $this->currentState->value,
            'current_state_label' => $this->currentState->getLabel(),
            'required_state' => $this->requiredState,
            'allowed_operations' => $this->getAllowedOperations()
        ], 403);
    }

    private function getAllowedOperations(): array
    {
        return match($this->currentState) {
            AccountState::ACTIVE => ['deposit', 'withdrawal', 'transfer'],
            AccountState::FROZEN => ['deposit', 'view_balance'],
            AccountState::SUSPENDED => ['view_balance'],
            AccountState::CLOSED => [],
            default => []
        };
    }

    public function getDetails(): array
    {
        $details = parent::getDetails();

        $details['account_data'] = [
            'account_id' => $this->account->id,
            'account_number' => $this->account->account_number,
            'account_type' => $this->account->accountType?->name,
            'current_state' => $this->currentState->value,
            'current_state_label' => $this->currentState->getLabel(),
            'required_state' => $this->requiredState,
            'state_transition_allowed' => $this->account->currentState->getNextPossibleStates()
        ];

        return $details;
    }
}
