<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permission_keys' => ['required', 'array'],
            'permission_keys.*' => ['string', Rule::exists('permissions', 'key')],
        ];
    }
}
