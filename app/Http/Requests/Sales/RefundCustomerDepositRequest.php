<?php

namespace App\Http\Requests\Sales;

class RefundCustomerDepositRequest extends SalesActionRequest
{
    public function rules(): array { return ['amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['nullable', 'string']]; }
}
