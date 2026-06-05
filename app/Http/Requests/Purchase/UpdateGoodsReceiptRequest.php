<?php

namespace App\Http\Requests\Purchase;

class UpdateGoodsReceiptRequest extends StoreGoodsReceiptRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['receipt_date'] = ['sometimes', 'date'];
        $rules['vendor_id'] = ['sometimes', 'integer'];
        $rules['lines'] = ['sometimes', 'array', 'min:1'];
        return $rules;
    }
}
