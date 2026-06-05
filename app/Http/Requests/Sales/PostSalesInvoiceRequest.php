<?php

namespace App\Http\Requests\Sales;

class PostSalesInvoiceRequest extends SalesActionRequest
{
    public function rules(): array
    {
        return [
            'applied_down_payment_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
