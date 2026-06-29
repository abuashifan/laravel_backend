<?php

namespace App\Http\Requests\Budget;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.project_id' => ['nullable', 'integer'],
            'lines.*.period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'lines.*.amount' => ['required', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }
}
