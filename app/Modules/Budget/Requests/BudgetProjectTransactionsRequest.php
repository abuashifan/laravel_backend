<?php

namespace App\Modules\Budget\Requests;

use App\Modules\Budget\Support\BudgetDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BudgetProjectTransactionsRequest extends FormRequest
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
            'account_id' => ['nullable', 'integer'],
            'direction' => ['nullable', 'string', Rule::in(BudgetDirection::all())],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
