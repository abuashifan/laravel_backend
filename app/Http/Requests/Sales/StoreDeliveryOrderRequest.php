<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:tenant.contacts,id'], 'delivery_date' => ['required', 'date'], 'sales_order_id' => ['nullable', 'integer'], 'warehouse_id' => ['nullable', 'integer'], 'shipping_address' => ['nullable', 'string'], 'source_type' => ['nullable', 'string'], 'source_id' => ['nullable', 'integer'], 'source_number' => ['nullable', 'string'], 'source_revision' => ['nullable', 'integer'], 'notes' => ['nullable', 'string'], 'internal_notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.sales_order_line_id' => ['nullable', 'integer'], 'lines.*.product_id' => ['nullable', 'integer'], 'lines.*.product_code' => ['nullable', 'string', 'max:100'], 'lines.*.description' => ['required', 'string'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_id' => ['nullable', 'integer'], 'lines.*.warehouse_id' => ['nullable', 'integer'], 'lines.*.department_id' => ['nullable', 'integer'], 'lines.*.project_id' => ['nullable', 'integer'], 'lines.*.source_line_type' => ['nullable', 'string'], 'lines.*.source_line_id' => ['nullable', 'integer'],
        ];
    }
}
