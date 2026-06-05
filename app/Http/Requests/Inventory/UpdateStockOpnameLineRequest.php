<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStockOpnameLineRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'physical_quantity' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string'],
        ];
    }
}

