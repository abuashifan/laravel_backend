<?php

namespace App\Http\Requests\FixedAssets;

use Illuminate\Foundation\Http\FormRequest;

class CapitalizeFixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'capitalization_date' => ['nullable', 'date_format:Y-m-d'],
            'source_type' => ['nullable', 'string'],
            'source_id' => ['nullable', 'integer'],
            'source_line_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
