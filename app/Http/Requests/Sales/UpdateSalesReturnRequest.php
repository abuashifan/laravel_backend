<?php

namespace App\Http\Requests\Sales;

class UpdateSalesReturnRequest extends StoreSalesReturnRequest
{
    public function rules(): array { $rules = parent::rules(); $rules['return_date'] = ['sometimes', 'date']; $rules['customer_id'] = ['sometimes', 'exists:tenant.contacts,id']; $rules['lines'] = ['sometimes', 'array', 'min:1']; return $rules; }
}
