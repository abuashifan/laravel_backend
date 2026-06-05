<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class StoreChartOfAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_code' => ['required', 'string', 'max:50'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', 'in:asset,liability,equity,revenue,expense'],
            'parent_account_id' => ['nullable', 'integer'],
            'normal_balance' => ['nullable', 'in:debit,credit'],
            'is_cash_bank' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $type = $this->input('account_type');
            $isCashBank = (bool) $this->boolean('is_cash_bank');

            if ($isCashBank && $type !== 'asset') {
                $validator->errors()->add('is_cash_bank', 'is_cash_bank hanya boleh untuk account_type asset.');
            }
        });
    }
}

