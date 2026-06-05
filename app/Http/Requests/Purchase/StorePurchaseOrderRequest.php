<?php

namespace App\Http\Requests\Purchase;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'exists:tenant.contacts,id'],
            'order_date' => ['required', 'date_format:Y-m-d'],
            'expected_date' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                $orderDate = $this->input('order_date');
                if (is_string($orderDate) && is_string($value) && $value < $orderDate) {
                    $fail('The expected date must be a date after or equal to order date.');
                }
            }],
            'purchase_request_id' => ['nullable', 'integer'],
            'vendor_address' => ['nullable', 'string'],
            'shipping_address' => ['nullable', 'string'],
            'vendor_quote_number' => ['nullable', 'string', 'max:100'],
            'contract_number' => ['nullable', 'string', 'max:100'],
            'buyer_id' => ['nullable', 'integer'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'is_taxable' => ['nullable', 'boolean'],
            'tax_included' => ['nullable', 'boolean'],
            'has_down_payment' => ['nullable', 'boolean'],
            'vendor_deposit' => ['nullable', 'array'],
            'vendor_deposit.deposit_date' => ['required_with:vendor_deposit', 'date'],
            'vendor_deposit.cash_bank_account_id' => ['required_with:vendor_deposit', 'integer'],
            'vendor_deposit.amount' => ['required_with:vendor_deposit', 'numeric', 'gt:0'],
            'vendor_deposit.notes' => ['nullable', 'string'],
            'header_discount_type' => ['nullable', 'in:percent,fixed_amount'],
            'header_discount_value' => ['nullable', 'numeric', 'min:0'],
            'source_type' => ['nullable', 'string'],
            'source_id' => ['nullable', 'integer'],
            'source_number' => ['nullable', 'string'],
            'source_revision' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_request_line_id' => ['nullable', 'integer'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.product_code' => ['nullable', 'string', 'max:100'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_type' => ['nullable', 'in:percent,fixed_amount'],
            'lines.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.warehouse_id' => ['nullable', 'integer'],
            'lines.*.department_id' => ['nullable', 'integer'],
            'lines.*.project_id' => ['nullable', 'integer'],
            'lines.*.expense_account_id' => ['nullable', 'integer'],
            'lines.*.source_line_type' => ['nullable', 'string'],
            'lines.*.source_line_id' => ['nullable', 'integer'],
        ];
    }
}
