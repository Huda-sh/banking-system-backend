<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAccountLeafRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_type_id' => 'required|exists:account_types,id',
            'parent_account_id' => 'nullable|exists:accounts,id',
            'currency' => [
                'required_without:parent_account_id',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
            ],
            'initial_deposit' => 'required|numeric|min:0|decimal:0,2',
            'user_ids' => 'nullable|array|min:1',
            'user_ids.*' => 'required|exists:users,id',
            'owner_user_id' => 'required|exists:users,id',
        ];
    }

    public function messages(): array
    {
        return [
            'currency.required_without' => 'Currency is required when no parent account is specified',
            'currency.regex' => 'Currency must be a 3-letter uppercase code (e.g., USD, EUR)',
            'initial_deposit.required' => 'Initial deposit amount is required',
            'initial_deposit.min' => 'Initial deposit cannot be negative',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper($this->input('currency')),
            ]);
        }

        if ($this->has('owner_user_id') && $this->has('user_ids')) {
            $userIds = $this->input('user_ids', []);
            if (!in_array($this->input('owner_user_id'), $userIds)) {
                $userIds[] = $this->input('owner_user_id');
                $this->merge(['user_ids' => $userIds]);
            }
        }
    }
}

