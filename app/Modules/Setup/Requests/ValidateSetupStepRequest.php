<?php

namespace App\Modules\Setup\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateSetupStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'step' => ['required', 'string', 'max:80'],
            'opening_date' => ['nullable', 'date'],
            'confirm_no_opening_fixed_assets' => ['nullable', 'boolean'],
            'confirm_opening_balance_skipped' => ['nullable', 'boolean'],
        ];
    }
}
