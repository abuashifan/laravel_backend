<?php

namespace App\Modules\Purchase\Requests;

use App\Shared\Rules\PostableAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVendorPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date'],
            'vendor_id' => ['required', 'exists:tenant.contacts,id'],
            'vendor_bill_id' => ['nullable', 'integer'],
            'cash_bank_account_id' => ['required', 'integer', 'exists:tenant.chart_of_accounts,id', new PostableAccount],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.vendor_bill_id' => ['required_with:lines', 'integer'],
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
