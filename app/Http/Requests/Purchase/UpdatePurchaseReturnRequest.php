<?php

namespace App\Http\Requests\Purchase;

class UpdatePurchaseReturnRequest extends StorePurchaseReturnRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['return_date'] = ['sometimes', 'date'];
        $rules['vendor_id'] = ['sometimes', 'exists:tenant.contacts,id'];
        $rules['lines'] = ['sometimes', 'array', 'min:1'];

        return $rules;
    }
}
