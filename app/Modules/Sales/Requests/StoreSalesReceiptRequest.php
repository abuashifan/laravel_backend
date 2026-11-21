<?php

namespace App\Modules\Sales\Requests;

use App\Shared\Rules\PostableAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSalesReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receipt_date' => ['required', 'date'],
            'customer_id' => ['required', 'exists:tenant.contacts,id'],
            'sales_invoice_id' => ['nullable', 'integer'],
            'cash_bank_account_id' => ['required', 'integer', 'exists:tenant.chart_of_accounts,id', new PostableAccount],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.sales_invoice_id' => ['required_with:lines', 'integer'],
            'lines.*.amount' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.settlement_discount_amount' => ['sometimes', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'lines.*.settlement_discount_account_id' => ['nullable', 'integer', new PostableAccount,
                Rule::exists('tenant.chart_of_accounts', 'id')->where('is_active', true)->whereIn('account_type', ['expense', 'revenue'])],
            'lines.*.settlement_discount_note' => ['nullable', 'string', 'max:240'],
            'lines.*.description' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('lines', []) as $index => $line) {
                if (! is_array($line) || ! is_numeric($line['settlement_discount_amount'] ?? null)) {
                    continue;
                }
                if ((float) $line['settlement_discount_amount'] > 0 && empty($line['settlement_discount_account_id'])) {
                    $validator->errors()->add("lines.{$index}.settlement_discount_account_id", 'Pilih akun untuk potongan pelunasan.');
                }
            }
        });
    }
}
