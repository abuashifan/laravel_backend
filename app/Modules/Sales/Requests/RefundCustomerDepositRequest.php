<?php

namespace App\Modules\Sales\Requests;

class RefundCustomerDepositRequest extends SalesActionRequest
{
    public function rules(): array { return ['amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['nullable', 'string']]; }
}
