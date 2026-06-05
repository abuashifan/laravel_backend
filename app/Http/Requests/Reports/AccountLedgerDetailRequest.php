<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Concerns\HasReportDateFilters;
use App\Http\Requests\Concerns\HasReportDimensionFilters;
use Illuminate\Foundation\Http\FormRequest;

class AccountLedgerDetailRequest extends FormRequest
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
            'include_opening_balance' => ['nullable', 'boolean'],
            'include_zero_balance' => ['nullable', 'boolean'],
            'include_source_info' => ['nullable', 'boolean'],
            'include_dimensions' => ['nullable', 'boolean'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ];
    }
}
