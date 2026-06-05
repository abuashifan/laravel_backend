<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyTransactionDefaultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'default_payment_term_id' => ['nullable', 'integer', 'exists:tenant.payment_terms,id'],
        ];
    }
}
