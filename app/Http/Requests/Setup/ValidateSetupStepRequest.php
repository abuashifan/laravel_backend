<?php

namespace App\Http\Requests\Setup;

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
        ];
    }
}
