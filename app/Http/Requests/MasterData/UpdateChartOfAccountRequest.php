<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChartOfAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_code' => ['sometimes', 'string', 'max:50'],
            'account_name' => ['sometimes', 'string', 'max:255'],
            'account_type' => ['sometimes', 'in:asset,liability,equity,revenue,expense'],
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
            $type = $this->input('account_type', $this->route('id') ? null : null);
            $isCashBank = $this->has('is_cash_bank') ? (bool) $this->boolean('is_cash_bank') : null;

            if ($isCashBank === true && $type !== null && $type !== 'asset') {
                $validator->errors()->add('is_cash_bank', 'is_cash_bank hanya boleh untuk account_type asset.');
            }
        });
    }
}

