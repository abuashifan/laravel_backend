<?php

namespace App\Http\Requests\FixedAssets;

use Illuminate\Foundation\Http\FormRequest;

class DisposeFixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'disposal_date' => ['required', 'date_format:Y-m-d'],
            'disposal_type' => ['required', 'in:sale,write_off,scrap,lost'],
            'disposed_quantity' => ['required', 'numeric', 'gt:0'],
            'proceeds_amount' => ['nullable', 'numeric', 'min:0'],
            'cash_bank_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'receivable_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
