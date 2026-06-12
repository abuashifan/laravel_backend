<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorPaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date'],
            'vendor_id' => ['required', 'exists:tenant.contacts,id'],
            'vendor_bill_id' => ['nullable', 'integer'],
            'cash_bank_account_id' => ['required', 'integer'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.vendor_bill_id' => ['required_with:lines', 'integer'],
            'lines.*.amount' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string'],
        ];
    }
}
