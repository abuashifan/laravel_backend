<?php

namespace App\Http\Requests\Purchase;

class UpdatePurchaseOrderRequest extends StorePurchaseOrderRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['vendor_id'] = ['sometimes', 'exists:tenant.contacts,id'];
        $rules['order_date'] = ['sometimes', 'date'];
        $rules['lines'] = ['sometimes', 'array', 'min:1'];

        return $rules;
    }
}
