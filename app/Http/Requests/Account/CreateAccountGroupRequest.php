<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class CreateAccountGroupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_type_id' => 'required|exists:account_types,id',
            'currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/',
            'user_ids' => 'nullable|array|min:1',
            'user_ids.*' => 'required|exists:users,id',
            'owner_user_id' => 'required|exists:users,id',
        ];
    }

    public function messages(): array
    {
        return [
            'currency.regex' => 'Currency must be a 3-letter uppercase code (e.g., USD, EUR)',
            'owner_user_id.required' => 'An owner must be specified for the account group',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Ensure owner is in user_ids array
        if ($this->has('owner_user_id') && $this->has('user_ids')) {
            $userIds = $this->input('user_ids', []);
            if (!in_array($this->input('owner_user_id'), $userIds)) {
                $userIds[] = $this->input('owner_user_id');
                $this->merge(['user_ids' => $userIds]);
            }
        }
    }
}
