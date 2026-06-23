<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'return_date' => ['required', 'date'],
            'vendor_id' => ['required', 'exists:tenant.contacts,id'],
            'vendor_bill_id' => ['nullable', 'integer', 'required_without:goods_receipt_id'],
            'goods_receipt_id' => ['nullable', 'integer', 'required_without:vendor_bill_id'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'source_type' => ['nullable', 'string'],
            'source_id' => ['nullable', 'integer'],
            'source_number' => ['nullable', 'string'],
            'source_revision' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.vendor_bill_line_id' => ['nullable', 'integer', 'required_without:lines.*.goods_receipt_line_id'],
            'lines.*.goods_receipt_line_id' => ['nullable', 'integer', 'required_without:lines.*.vendor_bill_line_id'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.product_code' => ['nullable', 'string'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.warehouse_id' => ['nullable', 'integer'],
            'lines.*.department_id' => ['nullable', 'integer'],
            'lines.*.project_id' => ['nullable', 'integer'],
            'lines.*.expense_account_id' => ['nullable', 'integer'],
        ];
    }
}
