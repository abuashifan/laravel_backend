<?php

namespace App\Http\Requests\Sales;

class UpdateSalesOrderRequest extends StoreSalesOrderRequest
{
    public function rules(): array { $rules = parent::rules(); $rules['customer_id'] = ['sometimes', 'exists:tenant.contacts,id']; $rules['order_date'] = ['sometimes', 'date']; $rules['lines'] = ['sometimes', 'array', 'min:1']; return $rules; }
}
