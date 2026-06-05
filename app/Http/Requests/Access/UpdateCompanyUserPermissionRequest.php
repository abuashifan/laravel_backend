<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyUserPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'overrides' => ['array'],
            'overrides.*.permission_key' => ['required', 'string', 'exists:permissions,key', 'distinct'],
            'overrides.*.effect' => ['required', Rule::in(['allow', 'deny'])],
            'overrides.*.reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
