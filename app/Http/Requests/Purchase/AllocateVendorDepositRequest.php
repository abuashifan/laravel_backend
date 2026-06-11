<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class AllocateVendorDepositRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'allocated_amount' => ['nullable', 'required_without:amount', 'numeric', 'gt:0'],
            'amount' => ['nullable', 'required_without:allocated_amount', 'numeric', 'gt:0'],
            'allocation_date' => ['nullable', 'date'],
            'source_context' => ['nullable', 'in:vendor_bill,vendor_payment'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function allocatedAmount(): float
    {
        return (float) ($this->validated('allocated_amount') ?? $this->validated('amount'));
    }
}
