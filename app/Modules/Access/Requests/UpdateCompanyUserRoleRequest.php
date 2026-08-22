<?php

namespace App\Modules\Access\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'role' => ['nullable', 'string', 'max:100'],
            'reset_overrides' => ['boolean'],
        ];
    }
}
