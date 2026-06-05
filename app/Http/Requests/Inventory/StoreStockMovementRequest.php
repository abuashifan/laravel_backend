<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockMovementRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'movement_date' => ['required', 'date'],
            'movement_type' => ['required', 'string'],
            'source_type' => ['nullable', 'string'],
            'source_id' => ['nullable', 'integer'],
            'source_number' => ['nullable', 'string'],
            'source_revision' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.warehouse_id' => ['required', 'integer'],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.department_id' => ['nullable', 'integer'],
            'lines.*.project_id' => ['nullable', 'integer'],
            'lines.*.source_line_type' => ['nullable', 'string'],
            'lines.*.source_line_id' => ['nullable', 'integer'],
            'lines.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }
}

