<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorDepositRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'deposit_date' => ['required', 'date'],
            'vendor_id' => ['required', 'exists:tenant.contacts,id'],
            'purchase_order_id' => ['nullable', 'integer'],
            'cash_bank_account_id' => ['required', 'integer'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'source_type' => ['nullable', 'string'],
            'source_id' => ['nullable', 'integer'],
            'source_number' => ['nullable', 'string'],
            'source_revision' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
