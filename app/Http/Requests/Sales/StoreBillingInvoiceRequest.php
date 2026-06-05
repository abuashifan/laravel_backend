<?php

namespace App\Http\Requests\Sales;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreBillingInvoiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'billing_date' => ['required', 'date_format:Y-m-d'],
            'payment_term_id' => ['nullable', 'integer', 'exists:tenant.payment_terms,id'],
            'due_date' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                $billingDate = $this->input('billing_date');
                if (is_string($billingDate) && is_string($value) && $value < $billingDate) {
                    $fail('The due date must be a date after or equal to billing date.');
                }
            }],
            'customer_id' => ['required', 'exists:tenant.contacts,id'],
            'sales_invoice_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_invoice_line_id' => ['nullable', 'integer'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
