<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;

class CopyAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_company_user_id' => ['required', 'integer', 'exists:company_users,id'],
            'copy_role' => ['boolean'],
            'copy_overrides' => ['boolean'],
        ];
    }
}
