<?php

namespace App\Http\Requests\CashBank;

use Illuminate\Foundation\Http\FormRequest;

class ReopenBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10'],
        ];
    }
}
