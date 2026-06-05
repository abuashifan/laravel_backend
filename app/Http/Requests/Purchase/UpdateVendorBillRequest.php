<?php

namespace App\Http\Requests\Purchase;

class UpdateVendorBillRequest extends StoreVendorBillRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['vendor_id'] = ['sometimes', 'exists:tenant.contacts,id'];
        $rules['bill_date'] = ['sometimes', 'date'];
        $rules['lines'] = ['sometimes', 'array', 'min:1'];
        return $rules;
    }
}
