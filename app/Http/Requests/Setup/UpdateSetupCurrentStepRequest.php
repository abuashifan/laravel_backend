<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSetupCurrentStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_step' => ['required', 'string', 'max:80'],
            'opening_date' => ['nullable', 'date'],
        ];
    }
}
