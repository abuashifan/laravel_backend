<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Concerns\HasReportDimensionFilters;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class ReconciliationReportRequest extends FormRequest
{
    use HasReportDimensionFilters;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('date_from') && ! $this->has('start_date')) {
            $merge['start_date'] = $this->input('date_from');
        }
        if ($this->has('date_to') && ! $this->has('end_date')) {
            $merge['end_date'] = $this->input('date_to');
        }
        if ($this->has('only_difference')) {
            $merge['only_difference'] = $this->normalizeBooleanQuery('only_difference');
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', $this->endDateAfterStartRule()],
            'as_of_date' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'account_id' => ['nullable', 'integer'],
            ...$this->dimensionFilterRules(),
            'only_difference' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function endDateAfterStartRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $start = $this->input('start_date');
            $startTimestamp = is_string($start) ? strtotime($start) : false;
            $endTimestamp = is_string($value) ? strtotime($value) : false;

            if ($startTimestamp !== false && $endTimestamp !== false && $endTimestamp < $startTimestamp) {
                $fail('The end date must be a date after or equal to start date.');
            }
        };
    }

    private function normalizeBooleanQuery(string $key): mixed
    {
        $value = $this->input($key);
        if ($value === true || $value === false || $value === 1 || $value === 0 || $value === '1' || $value === '0') {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'true') return true;
            if ($normalized === 'false') return false;
        }

        return $value;
    }
}
