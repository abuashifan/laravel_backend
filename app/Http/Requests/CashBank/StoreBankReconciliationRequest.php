<?php

namespace App\Http\Requests\CashBank;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'cash_bank_account_id' => ['required', 'exists:tenant.chart_of_accounts,id'],
            'statement_start_date' => ['required', 'date_format:Y-m-d'],
            'statement_end_date' => ['required', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                $startDate = $this->input('statement_start_date');
                if (is_string($startDate) && is_string($value) && $value < $startDate) {
                    $fail('The statement end date must be a date after or equal to statement start date.');
                }
            }],
            'statement_opening_balance' => ['nullable', 'numeric'],
            'statement_ending_balance' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
