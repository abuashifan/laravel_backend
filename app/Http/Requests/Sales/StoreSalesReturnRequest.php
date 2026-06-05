<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['return_date' => ['required', 'date'], 'customer_id' => ['required', 'exists:tenant.contacts,id'], 'sales_invoice_id' => ['nullable', 'integer'], 'delivery_order_id' => ['nullable', 'integer'], 'reason' => ['nullable', 'string'], 'notes' => ['nullable', 'string'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.sales_invoice_line_id' => ['nullable', 'integer'], 'lines.*.delivery_order_line_id' => ['nullable', 'integer'], 'lines.*.description' => ['required', 'string'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'], 'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'], 'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'], 'lines.*.line_total' => ['nullable', 'numeric', 'min:0']];
    }
}
