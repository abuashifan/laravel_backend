<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:30'],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'precision' => ['sometimes', 'required', 'integer', 'min:0', 'max:8'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Code is required.',
            'name.required' => 'Name is required.',
            'precision.required' => 'Precision is required.',
            'precision.integer' => 'Precision must be a number.',
            'precision.min' => 'Precision must be at least 0.',
            'precision.max' => 'Precision may not be greater than 8.',
        ];
    }
}
