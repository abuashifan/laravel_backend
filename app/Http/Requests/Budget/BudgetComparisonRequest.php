<?php

namespace App\Http\Requests\Budget;

use Illuminate\Foundation\Http\FormRequest;

class BudgetComparisonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'budget_period_id' => ['required', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date'],
        ];
    }
}
