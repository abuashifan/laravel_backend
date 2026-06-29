<?php

namespace App\Http\Requests\Budget;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'fiscal_year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'period_from' => ['sometimes', 'date'],
            'period_to' => ['sometimes', 'date'],
        ];
    }
}
