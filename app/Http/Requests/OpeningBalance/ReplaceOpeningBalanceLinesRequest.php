<?php

namespace App\Http\Requests\OpeningBalance;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceOpeningBalanceLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array'],
            'lines.*.account_id' => ['required', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.source_type' => ['nullable', 'string', 'max:80'],
            'lines.*.source_id' => ['nullable', 'integer'],
            'lines.*.source_line_id' => ['nullable', 'integer'],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }
}
