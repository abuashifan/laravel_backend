<?php

namespace App\Modules\CashBank\Requests;

use App\Shared\Rules\PostableAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Kosong/tidak dikirim -> nomor digenerate otomatis di
            // CashReceiptService::create(). User bisa memilih isi manual lewat
            // form, jadi harus unik terhadap nomor yang sudah ada (termasuk
            // hasil generate otomatis, satu kolom yang sama dipakai keduanya).
            'receipt_number' => ['nullable', 'string', 'max:190', Rule::unique('tenant.cash_receipts', 'receipt_number')],
            'receipt_date' => ['required', 'date'],
            'cash_bank_account_id' => ['required', 'exists:tenant.chart_of_accounts,id', new PostableAccount],
            'contact_id' => ['nullable', 'exists:tenant.contacts,id'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.account_id' => ['required_with:lines', 'exists:tenant.chart_of_accounts,id', new PostableAccount],
            'lines.*.amount' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.department_id' => ['nullable', 'integer'],
            'lines.*.project_id' => ['nullable', 'integer'],
            'lines.*.line_order' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
