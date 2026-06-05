<?php

namespace App\Http\Requests\CashBank;

use Illuminate\Foundation\Http\FormRequest;

class MarkBankReconciliationLinesRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'line_ids' => ['required', 'array', 'min:1'],
            'line_ids.*' => ['integer'],
            'cleared' => ['required', 'boolean'],
            'cleared_date' => ['nullable', 'date'],
        ];
    }
}

