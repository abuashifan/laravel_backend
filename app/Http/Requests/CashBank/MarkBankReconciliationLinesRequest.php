<?php

namespace App\Http\Requests\CashBank;

use App\Models\Tenant\BankReconciliation;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class MarkBankReconciliationLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'line_ids' => ['required', 'array', 'min:1'],
            'line_ids.*' => ['integer', function (string $attribute, mixed $value, Closure $fail): void {
                $reconciliationId = (int) $this->route('id');
                $exists = BankReconciliation::query()
                    ->whereKey($reconciliationId)
                    ->whereHas('lines', fn ($query) => $query->whereKey((int) $value))
                    ->exists();

                if (! $exists) {
                    $fail('The selected reconciliation line is invalid.');
                }
            }],
            'cleared' => ['required', 'boolean'],
            'cleared_date' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                if (! $this->boolean('cleared') || ! is_string($value)) {
                    return;
                }

                $reconciliation = BankReconciliation::query()->find((int) $this->route('id'));
                if (! $reconciliation) {
                    return;
                }

                $start = $reconciliation->statement_start_date->toDateString();
                $end = $reconciliation->statement_end_date->toDateString();
                if ($value < $start || $value > $end) {
                    $fail("The cleared date must be between {$start} and {$end}.");
                }
            }],
        ];
    }
}
