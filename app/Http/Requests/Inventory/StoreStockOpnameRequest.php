<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockOpnameRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'opname_date' => ['required', 'date'],
            'warehouse_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}

