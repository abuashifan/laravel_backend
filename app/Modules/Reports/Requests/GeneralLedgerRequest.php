<?php

namespace App\Modules\Reports\Requests;

use App\Modules\Reports\Requests\Concerns\HasReportDateFilters;
use App\Modules\Reports\Requests\Concerns\HasReportDimensionFilters;
use Illuminate\Foundation\Http\FormRequest;

class GeneralLedgerRequest extends FormRequest
{
    use HasReportDateFilters;
    use HasReportDimensionFilters;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['include_opening_balance', 'include_zero_balance'] as $key) {
            if ($this->has($key)) {
                $normalized[$key] = $this->normalizeBooleanQuery($key);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'account_id' => ['nullable', 'integer'],
            ...$this->dateFilterRules(),
            ...$this->dimensionFilterRules(),
            'include_opening_balance' => ['nullable', 'boolean'],
            'include_zero_balance' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', 'in:journal_date,journal_number,account_code'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'mode' => ['nullable', 'in:summary,detail'],
        ];
    }

    private function normalizeBooleanQuery(string $key): mixed
    {
        if (! $this->has($key)) {
            return null;
        }

        $value = $this->input($key);
        if ($value === true || $value === false || $value === 1 || $value === 0 || $value === '1' || $value === '0') {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'true') {
                return true;
            }
            if ($normalized === 'false') {
                return false;
            }
        }

        return $value;
    }
}
