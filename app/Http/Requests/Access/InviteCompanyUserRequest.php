<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;

class InviteCompanyUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'role' => ['nullable', 'string', 'max:100'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
