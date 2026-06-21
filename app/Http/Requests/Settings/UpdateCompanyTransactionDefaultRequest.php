<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyTransactionDefaultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'default_payment_term_id' => [
                'nullable',
                'integer',
                Rule::exists('tenant.payment_terms', 'id')->where('is_active', true),
            ],
        ];
    }
}
