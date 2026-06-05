<?php

namespace App\Http\Requests\Sales;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreSalesQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:tenant.contacts,id'],
            'quotation_date' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                $quotationDate = $this->input('quotation_date');
                if (is_string($quotationDate) && is_string($value) && $value < $quotationDate) {
                    $fail('The valid until date must be a date after or equal to quotation date.');
                }
            }],
            'customer_address' => ['nullable', 'string'],
            'quotation_for' => ['nullable', 'string', 'max:255'],
            'salesperson_id' => ['nullable', 'integer'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'is_taxable' => ['nullable', 'boolean'],
            'tax_included' => ['nullable', 'boolean'],
            'header_discount_type' => ['nullable', 'in:percent,fixed_amount'],
            'header_discount_value' => ['nullable', 'numeric', 'min:0'],
            'source_type' => ['nullable', 'string'],
            'source_id' => ['nullable', 'integer'],
            'source_number' => ['nullable', 'string'],
            'source_revision' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
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
