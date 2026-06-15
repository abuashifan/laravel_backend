<?php

namespace App\Http\Requests\FixedAssets;

use Illuminate\Foundation\Http\FormRequest;

class StoreFixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'fixed_asset_category_id' => ['required', 'integer', 'exists:tenant.fixed_asset_categories,id'],
            'acquisition_date' => ['required', 'date_format:Y-m-d'],
            'service_start_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:acquisition_date'],
            'useful_life_years' => ['nullable', 'integer', 'in:4,8,10,16,20'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'acquisition_cost' => ['required', 'numeric', 'min:0'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'department_id' => ['nullable', 'integer', 'exists:tenant.departments,id'],
            'project_id' => ['nullable', 'integer', 'exists:tenant.projects,id'],
            'source_type' => ['nullable', 'string'],
            'source_id' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
