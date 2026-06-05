<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyModuleSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_enabled' => ['nullable', 'boolean'],
            'purchase_enabled' => ['nullable', 'boolean'],
            'cash_bank_enabled' => ['nullable', 'boolean'],
            'inventory_enabled' => ['nullable', 'boolean'],
            'warehouse_enabled' => ['nullable', 'boolean'],
            'fixed_asset_enabled' => ['nullable', 'boolean'],
            'approval_enabled' => ['nullable', 'boolean'],
            'tax_enabled' => ['nullable', 'boolean'],
            'reports_enabled' => ['nullable', 'boolean'],
        ];
    }
}

