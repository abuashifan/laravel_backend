<?php

namespace App\Modules\Reports\Requests;

use App\Modules\Reports\Requests\Concerns\HasReportDateFilters;
use App\Modules\Reports\Requests\Concerns\HasReportDimensionFilters;
use Illuminate\Foundation\Http\FormRequest;

class TrialBalanceRequest extends FormRequest
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
            ...$this->dateFilterRules(),
            ...$this->dimensionFilterRules(),
            'include_zero_balance' => ['nullable', 'boolean'],
            'include_inactive_accounts' => ['nullable', 'boolean'],
            'account_type' => ['nullable', 'in:asset,liability,equity,revenue,expense'],
            'sort_by' => ['nullable', 'in:account_code,account_name,account_type'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ];
    }
}
