<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class PeriodEndPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', 'date_format:Y-m'],
            'period_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }

    public function period(): string
    {
        $period = $this->input('period', $this->query('period'));
        if (is_string($period) && preg_match('/^\d{4}-\d{2}$/', $period)) {
            return $period;
        }

        $year = $this->input('period_year', $this->query('period_year'));
        $month = $this->input('period_month', $this->query('period_month'));

        if ($year && $month) {
            return sprintf('%04d-%02d', (int) $year, (int) $month);
        }

        return '';
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->period() === '') {
                $validator->errors()->add('period', 'period wajib dalam format YYYY-MM.');
            }
        });
    }
}
