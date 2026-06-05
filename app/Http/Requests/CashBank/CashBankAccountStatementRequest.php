<?php

namespace App\Http\Requests\CashBank;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class CashBankAccountStatementRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'cash_bank_account_id' => ['required', 'exists:tenant.chart_of_accounts,id'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                $startDate = $this->input('start_date');
                if (is_string($startDate) && is_string($value) && $value < $startDate) {
                    $fail('The end date must be a date after or equal to start date.');
                }
            }],
        ];
    }
}
