<?php

namespace App\Http\Requests\Sales;

class UpdateSalesQuotationRequest extends StoreSalesQuotationRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['customer_id'] = ['sometimes', 'exists:tenant.contacts,id'];
        $rules['quotation_date'] = ['sometimes', 'date'];
        $rules['lines'] = ['sometimes', 'array', 'min:1'];

        return $rules;
    }
}
