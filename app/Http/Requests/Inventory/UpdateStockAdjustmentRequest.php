<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'adjustment_date' => ['sometimes', 'date'],
            'warehouse_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],

            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.product_id' => ['required_with:lines', 'integer'],
            'lines.*.warehouse_id' => ['required_with:lines', 'integer'],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.adjustment_type' => ['required_with:lines', 'in:increase,decrease'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.reason' => ['nullable', 'string'],
            'lines.*.department_id' => ['nullable', 'integer'],
            'lines.*.project_id' => ['nullable', 'integer'],
            'lines.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }
}

