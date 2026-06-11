<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_code' => ['nullable', 'string', 'max:50'],
            'name' => ['sometimes', 'string', 'max:255'],
            'contact_type' => ['nullable', 'in:customer,supplier,employee,other'],
            'payment_term_id' => ['nullable', 'integer', 'exists:tenant.payment_terms,id'],
            'receivable_account_id' => [
                'nullable',
                'integer',
                Rule::exists('tenant.chart_of_accounts', 'id')->where('account_type', 'asset')->where('is_active', true),
            ],
            'payable_account_id' => [
                'nullable',
                'integer',
                Rule::exists('tenant.chart_of_accounts', 'id')->where('account_type', 'liability')->where('is_active', true),
            ],
            'is_customer' => ['nullable', 'boolean'],
            'is_supplier' => ['nullable', 'boolean'],
            'is_employee' => ['nullable', 'boolean'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
