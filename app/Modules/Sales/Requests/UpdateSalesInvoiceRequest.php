<?php

namespace App\Modules\Sales\Requests;

class UpdateSalesInvoiceRequest extends StoreSalesInvoiceRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['customer_id'] = ['sometimes', 'exists:tenant.contacts,id'];
        $rules['invoice_date'] = ['sometimes', 'date'];
        $rules['lines'] = ['sometimes', 'array', 'min:1'];

        return $rules;
    }
}
