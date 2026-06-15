<?php

namespace App\Http\Requests\FixedAssets;

use Illuminate\Validation\Rule;

class UpdateFixedAssetCategoryRequest extends StoreFixedAssetCategoryRequest
{
    public function rules(): array
    {
        $id = $this->route('id');
        $rules = parent::rules();
        $rules['code'] = ['sometimes', 'string', 'max:50', 'alpha_dash', Rule::unique('tenant.fixed_asset_categories', 'code')->ignore($id)];
        $rules['name'] = ['sometimes', 'string', 'max:255'];
        $rules['asset_class'] = ['sometimes', 'in:tangible,intangible'];
        $rules['depreciation_type'] = ['sometimes', 'in:depreciation,amortization,none,impairment_only'];
        return $rules;
    }
}
