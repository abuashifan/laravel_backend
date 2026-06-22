<?php

namespace App\Http\Requests\CashBank;

use Illuminate\Foundation\Http\FormRequest;

class RefreshBankReconciliationLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reset_cleared' => ['sometimes', 'boolean'],
            'reason' => ['required_if:reset_cleared,true', 'nullable', 'string', 'min:10'],
        ];
    }
}
