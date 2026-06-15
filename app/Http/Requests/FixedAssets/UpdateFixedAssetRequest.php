<?php

namespace App\Http\Requests\FixedAssets;

class UpdateFixedAssetRequest extends StoreFixedAssetRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['sometimes', 'string', 'max:255'];
        $rules['fixed_asset_category_id'] = ['sometimes', 'integer', 'exists:tenant.fixed_asset_categories,id'];
        $rules['acquisition_date'] = ['sometimes', 'date_format:Y-m-d'];
        $rules['acquisition_cost'] = ['sometimes', 'numeric', 'min:0'];
        return $rules;
    }
}
