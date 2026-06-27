<?php

namespace App\Http\Requests\FixedAssets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFixedAssetCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('tenant.fixed_asset_categories', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'asset_class' => ['required', 'in:tangible,intangible'],
            'depreciation_type' => ['required', 'in:depreciation,amortization,none,impairment_only'],
            'default_useful_life_years' => ['nullable', 'integer', 'in:4,8,10,16,20'],
            'asset_account_id' => $this->accountRule('asset'),
            'accumulated_depreciation_account_id' => $this->accountRule('asset'),
            'depreciation_expense_account_id' => $this->accountRule('expense'),
            'clearing_account_id' => $this->accountRule('asset'),
            'disposal_gain_account_id' => $this->accountRule('revenue'),
            'disposal_loss_account_id' => $this->accountRule('expense'),
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    private function accountRule(string $accountType): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('tenant.chart_of_accounts', 'id')
                ->where('account_type', $accountType)
                ->where('is_active', true),
        ];
    }
}
