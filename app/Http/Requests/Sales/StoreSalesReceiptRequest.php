<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesReceiptRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['receipt_date' => ['required', 'date'], 'customer_id' => ['required', 'exists:tenant.contacts,id'], 'sales_invoice_id' => ['nullable', 'integer'], 'billing_invoice_id' => ['nullable', 'integer'], 'cash_bank_account_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'notes' => ['nullable', 'string']]; }
}
