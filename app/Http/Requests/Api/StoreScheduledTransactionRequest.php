<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Enums\TransactionType;
use App\Models\Account;
use Illuminate\Support\Facades\Auth;

class StoreScheduledTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $user = Auth::user();

        return [
            'type' => ['required', Rule::enum(TransactionType::class)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
            'metadata.*' => ['string'],
            'from_account_id' => [
                'nullable',
                'exists:accounts,id',
                function ($attribute, $value, $fail) use ($user) {
                    if ($value) {
                        $account = Account::find($value);
                        if (!$account || !$user->canAccessAccount($account)) {
                            $fail('You do not have access to the source account.');
                        }
                        if (!$account->currentState->canPerformOperation('withdraw')) {
                            $fail('Source account cannot perform withdrawals in current state.');
                        }
                    }
                }
            ],
            'to_account_id' => [
                'required',
                'exists:accounts,id',
                function ($attribute, $value, $fail) use ($user) {
                    $account = Account::find($value);
                    if (!$account || !$user->canAccessAccount($account)) {
                        $fail('You do not have access to the destination account.');
                    }
                    if (!$account->currentState->canPerformOperation('deposit')) {
                        $fail('Destination account cannot receive deposits in current state.');
                    }
                }
            ],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'max_executions' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['nullable', 'boolean']
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Transaction type is required',
            'type.enum' => 'Invalid transaction type',
            'amount.required' => 'Transaction amount is required',
            'amount.min' => 'Transaction amount must be at least 0.01',
            'amount.max' => 'Transaction amount cannot exceed 99,999,999.99',
            'currency.regex' => 'Currency must be 3 uppercase letters (e.g., USD, EUR)',
            'frequency.required' => 'Frequency is required for scheduled transactions',
            'frequency.in' => 'Invalid frequency. Must be daily, weekly, monthly, or yearly',
            'start_date.required' => 'Start date is required',
            'start_date.after_or_equal' => 'Start date cannot be in the past',
            'end_date.after' => 'End date must be after start date',
            'max_executions.max' => 'Maximum executions cannot exceed 1000'
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'currency' => $this->currency ?? 'USD',
            'description' => $this->description ?? $this->getDefaultDescription(),
            'is_active' => $this->boolean('is_active') ?? true
        ]);
    }

    private function getDefaultDescription(): string
    {
        $type = $this->input('type');
        $frequency = $this->input('frequency');
        return match($type) {
            'deposit' => "Scheduled deposit - {$frequency}",
            'withdrawal' => "Scheduled withdrawal - {$frequency}",
            'transfer' => "Scheduled transfer - {$frequency}",
            default => "Scheduled transaction - {$frequency}"
        };
    }
}
