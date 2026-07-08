<?php

namespace App\Modules\Reports\Requests;

use App\Modules\Reports\Requests\Concerns\HasReportDimensionFilters;
use Illuminate\Foundation\Http\FormRequest;

class BalanceSheetRequest extends FormRequest
{
    use HasReportDimensionFilters;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'as_of_date' => ['required', 'date'],
            ...$this->dimensionFilterRules(),
            'include_zero_balance' => ['nullable', 'boolean'],
            'include_inactive_accounts' => ['nullable', 'boolean'],
            'group_by' => ['nullable', 'string', 'in:account_type,none'],
        ];
    }
}
