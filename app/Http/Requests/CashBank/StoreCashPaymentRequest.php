<?php

namespace App\Http\Requests\CashBank;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date'],
            'cash_bank_account_id' => ['required', 'exists:tenant.chart_of_accounts,id'],
            'contact_id' => ['nullable', 'exists:tenant.contacts,id'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'exists:tenant.chart_of_accounts,id'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.department_id' => ['nullable', 'exists:tenant.departments,id'],
            'lines.*.project_id' => ['nullable', 'exists:tenant.projects,id'],
            'lines.*.line_order' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
