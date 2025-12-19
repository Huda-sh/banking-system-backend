<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => [
                'required',
                'string',
                Rule::in(['active', 'frozen', 'suspended', 'closed']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'state.required' => 'State is required',
            'state.in' => 'State must be one of: active, frozen, suspended, closed',
        ];
    }
}

