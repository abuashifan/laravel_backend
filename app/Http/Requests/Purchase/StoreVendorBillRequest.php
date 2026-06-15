<?php

namespace App\Http\Requests\Purchase;

use App\Models\Tenant\ChartOfAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreVendorBillRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'exists:tenant.contacts,id'],
            'bill_date' => ['required', 'date_format:Y-m-d'],
            'payment_term_id' => ['nullable', 'integer', 'exists:tenant.payment_terms,id'],
            'due_date' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                $billDate = $this->input('bill_date');
                if (is_string($billDate) && is_string($value) && $value < $billDate) {
                    $fail('The due date must be a date after or equal to bill date.');
                }
            }],
            'vendor_invoice_number' => ['nullable', 'string'],
            'vendor_address' => ['nullable', 'string'],
            'purchase_order_id' => ['nullable', 'integer'],
            'goods_receipt_id' => ['nullable', 'integer'],
            'ap_account_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }

                $exists = ChartOfAccount::query()
                    ->whereKey((int) $value)
                    ->where('account_type', 'liability')
                    ->where('is_active', true)
                    ->exists();

                if (! $exists) {
                    $fail('The selected payable account must be an active liability account.');
                }
            }],
            'buyer_id' => ['nullable', 'integer'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'is_taxable' => ['nullable', 'boolean'],
            'tax_included' => ['nullable', 'boolean'],
            'header_discount_type' => ['nullable', 'in:percent,fixed_amount'],
            'header_discount_value' => ['nullable', 'numeric', 'min:0'],
            'applied_vendor_deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'source_type' => ['nullable', 'string'],
            'source_id' => ['nullable', 'integer'],
            'source_number' => ['nullable', 'string'],
            'source_revision' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_classification' => ['nullable', 'in:inventory,fixed_asset'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer'],
            'lines.*.goods_receipt_line_id' => ['nullable', 'integer'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.product_code' => ['nullable', 'string'],
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
            'lines.*.expense_account_id' => ['prohibited'],
            'lines.*.fixed_asset_category_id' => ['nullable', 'integer', 'exists:tenant.fixed_asset_categories,id'],
            'lines.*.source_line_type' => ['nullable', 'string'],
            'lines.*.source_line_id' => ['nullable', 'integer'],
        ];
    }
}
