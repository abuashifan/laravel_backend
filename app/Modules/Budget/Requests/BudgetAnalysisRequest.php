<?php

namespace App\Modules\Budget\Requests;

use App\Modules\Budget\Services\BudgetAllocationResolver;
use App\Modules\Budget\Services\BudgetAnalysisService;
use App\Modules\Budget\Support\BudgetDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BudgetAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'budget_period_id' => ['required', 'integer'],
            // Allowlist dipasang di dua tempat: di sini supaya user dapat pesan
            // validasi yang rapi, dan di service supaya nilai liar tetap ditolak
            // walau service dipanggil dari jalur lain.
            'group_by' => ['nullable', 'array'],
            'group_by.*' => ['string', Rule::in(BudgetAnalysisService::GROUPABLE)],
            'mode' => ['nullable', 'string', Rule::in(BudgetAnalysisService::MODES)],
            'allocation' => ['nullable', 'string', Rule::in(BudgetAllocationResolver::modes())],
            'version' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'account_id' => ['nullable', 'integer'],
            'account_type' => ['nullable', 'string', 'max:20'],
            'direction' => ['nullable', 'string', Rule::in(BudgetDirection::all())],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
