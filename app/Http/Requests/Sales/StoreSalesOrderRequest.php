<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:tenant.contacts,id'], 'order_date' => ['required', 'date'], 'quotation_id' => ['nullable', 'integer'],
            'customer_address' => ['nullable', 'string'], 'shipping_address' => ['nullable', 'string'], 'customer_po_number' => ['nullable', 'string', 'max:100'], 'contract_number' => ['nullable', 'string', 'max:100'],
            'salesperson_id' => ['nullable', 'integer'], 'currency_code' => ['nullable', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'is_taxable' => ['nullable', 'boolean'], 'tax_included' => ['nullable', 'boolean'], 'has_down_payment' => ['nullable', 'boolean'],
            'down_payment' => ['nullable', 'array'], 'down_payment.deposit_date' => ['required_with:down_payment', 'date'], 'down_payment.cash_bank_account_id' => ['required_with:down_payment', 'integer'], 'down_payment.amount' => ['required_with:down_payment', 'numeric', 'gt:0'], 'down_payment.notes' => ['nullable', 'string'],
            'header_discount_type' => ['nullable', 'in:percent,fixed_amount'], 'header_discount_value' => ['nullable', 'numeric', 'min:0'], 'source_type' => ['nullable', 'string'], 'source_id' => ['nullable', 'integer'], 'source_number' => ['nullable', 'string'], 'source_revision' => ['nullable', 'integer'], 'notes' => ['nullable', 'string'], 'internal_notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.quotation_line_id' => ['nullable', 'integer'], 'lines.*.product_id' => ['nullable', 'integer'], 'lines.*.product_code' => ['nullable', 'string', 'max:100'], 'lines.*.description' => ['required', 'string'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_id' => ['nullable', 'integer'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'], 'lines.*.discount_type' => ['nullable', 'in:percent,fixed_amount'], 'lines.*.discount_value' => ['nullable', 'numeric', 'min:0'], 'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0'], 'lines.*.warehouse_id' => ['nullable', 'integer'], 'lines.*.department_id' => ['nullable', 'integer'], 'lines.*.project_id' => ['nullable', 'integer'], 'lines.*.source_line_type' => ['nullable', 'string'], 'lines.*.source_line_id' => ['nullable', 'integer'],
        ];
    }
}
