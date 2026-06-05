<?php

namespace App\Http\Requests\Access;

use App\Services\Tenant\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $roleId = $this->route('roleId');
        $companyId = app(TenantContext::class)->companyId();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                'alpha_dash',
                Rule::unique('roles', 'slug')
                    ->ignore($roleId)
                    ->where(fn ($query) => $query->where('company_id', $companyId)->orWhere('is_system', true)),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
