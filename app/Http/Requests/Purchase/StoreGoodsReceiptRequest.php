<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required_without:purchase_order_id', 'integer'],
            'receipt_date' => ['required', 'date'],
            'purchase_order_id' => ['nullable', 'integer'],
            'purchase_order_number' => ['nullable', 'string'],
            'warehouse_id' => ['nullable', 'integer'],
            'source_type' => ['nullable', 'string'],
            'source_id' => ['nullable', 'integer'],
            'source_number' => ['nullable', 'string'],
            'source_revision' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.product_code' => ['nullable', 'string', 'max:100'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.warehouse_id' => ['nullable', 'integer'],
            'lines.*.department_id' => ['nullable', 'integer'],
            'lines.*.project_id' => ['nullable', 'integer'],
            'lines.*.expense_account_id' => ['nullable', 'integer'],
            'lines.*.source_line_type' => ['nullable', 'string'],
            'lines.*.source_line_id' => ['nullable', 'integer'],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }
}
