<?php

namespace App\Http\Requests\CashBank;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankTransferRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'transfer_date' => ['required', 'date'],
            'from_cash_bank_account_id' => ['required', 'exists:tenant.chart_of_accounts,id'],
            'to_cash_bank_account_id' => ['required', 'exists:tenant.chart_of_accounts,id', 'different:from_cash_bank_account_id'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}

