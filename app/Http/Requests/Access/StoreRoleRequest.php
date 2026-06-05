<?php

namespace App\Http\Requests\Access;

use App\Services\Tenant\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app(TenantContext::class)->companyId();

        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                'required',
                'string',
                'max:150',
                'alpha_dash',
                Rule::unique('roles', 'slug')->where(
                    fn ($query) => $query->where('company_id', $companyId)->orWhere('is_system', true)
                ),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'permission_keys' => ['array'],
            'permission_keys.*' => ['string', Rule::exists('permissions', 'key')],
        ];
    }
}
