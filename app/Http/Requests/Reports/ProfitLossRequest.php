<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Concerns\HasReportDateFilters;
use App\Http\Requests\Concerns\HasReportDimensionFilters;
use Illuminate\Foundation\Http\FormRequest;

class ProfitLossRequest extends FormRequest
{
    use HasReportDateFilters;
    use HasReportDimensionFilters;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->dateFilterRules(required: true),
            ...$this->dimensionFilterRules(),
            'include_zero_balance' => ['nullable', 'boolean'],
            'include_inactive_accounts' => ['nullable', 'boolean'],
            'group_by' => ['nullable', 'string', 'in:account_type,none'],
        ];
    }
}
