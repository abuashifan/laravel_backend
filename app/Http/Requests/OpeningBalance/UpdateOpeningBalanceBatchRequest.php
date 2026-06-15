<?php

namespace App\Http\Requests\OpeningBalance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOpeningBalanceBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'opening_date' => ['sometimes', 'date'],
            'fiscal_year' => ['nullable', 'integer', 'min:1900', 'max:2200'],
            'type' => ['sometimes', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
