<?php

namespace App\Http\Requests\Sales;

use App\Models\Tenant\ChartOfAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreSalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:tenant.contacts,id'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'payment_term_id' => ['nullable', 'integer', 'exists:tenant.payment_terms,id'],
            'due_date' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                $invoiceDate = $this->input('invoice_date');
                if (is_string($invoiceDate) && is_string($value) && $value < $invoiceDate) {
                    $fail('The due date must be a date after or equal to invoice date.');
                }
            }],
            'customer_address' => ['nullable', 'string'],
            'sales_order_id' => ['nullable', 'integer'],
            'delivery_order_id' => ['nullable', 'integer'],
            'proforma_invoice_id' => ['nullable', 'integer'],
            'ar_account_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }

                $exists = ChartOfAccount::query()
                    ->whereKey((int) $value)
                    ->where('account_type', 'asset')
                    ->where('is_active', true)
                    ->exists();

                if (! $exists) {
                    $fail('The selected receivable account must be an active asset account.');
                }
            }],
            'salesperson_id' => ['nullable', 'integer'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'is_taxable' => ['nullable', 'boolean'],
            'tax_included' => ['nullable', 'boolean'],
            'header_discount_type' => ['nullable', 'in:percent,fixed_amount'],
            'header_discount_value' => ['nullable', 'numeric', 'min:0'],
            'applied_down_payment_amount' => ['nullable', 'numeric', 'min:0'],
            'source_type' => ['nullable', 'string'],
            'source_id' => ['nullable', 'integer'],
            'source_number' => ['nullable', 'string'],
            'source_revision' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['nullable', 'integer'],
            'lines.*.delivery_order_line_id' => ['nullable', 'integer'],
            'lines.*.proforma_invoice_line_id' => ['nullable', 'integer'],
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
            'lines.*.source_line_type' => ['nullable', 'string'],
            'lines.*.source_line_id' => ['nullable', 'integer'],
        ];
    }
}
