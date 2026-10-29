<?php

namespace App\Modules\Access\Requests;

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
        return ['full_access' => ['required', 'boolean'], 'permissions' => ['present', 'array'], 'permissions.*' => ['required', 'string', 'distinct', Rule::in(config('permissions.matrix_keys', [])), 'exists:permissions,key']];
    }
}
