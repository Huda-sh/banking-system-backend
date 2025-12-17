<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
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
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $this->user_id,
            'phone' => 'nullable|string|max:255|regex:/^[0-9]+$/|unique:users,phone,' . $this->user_id,
            'national_id' => 'required|string|max:255|regex:/^[0-9]+$/|unique:users,national_id,' . $this->user_id,
            'date_of_birth' => 'required|date_format:Y-m-d|before:today',
            'address' => 'required|string|max:255',
            'roles' => 'required|array',
            'roles.*' => 'required|string',
        ];
    }
}
