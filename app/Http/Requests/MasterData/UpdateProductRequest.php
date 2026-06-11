<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_code' => ['nullable', 'string', 'max:50'],
            'product_name' => ['sometimes', 'string', 'max:255'],
            'product_type' => ['nullable', 'in:goods,service,non_inventory'],
            'product_category_id' => ['nullable', 'integer'],
            'unit_id' => ['nullable', 'integer'],
            'is_stock_item' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],
            'sales_account_id' => ['nullable', 'integer', Rule::exists('tenant.chart_of_accounts', 'id')->where('account_type', 'revenue')->where('is_active', true)],
            'purchase_account_id' => ['nullable', 'integer'],
            'inventory_account_id' => ['nullable', 'integer'],
            'cogs_account_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $isStockItem = $this->has('is_stock_item') ? (bool) $this->boolean('is_stock_item') : null;
            $unitId = $this->input('unit_id');
            $type = $this->input('product_type');

            if ($isStockItem === true && empty($unitId)) {
                $validator->errors()->add('unit_id', 'unit_id wajib untuk stock item.');
            }

            if ($type === 'service' && $isStockItem === true) {
                $validator->errors()->add('is_stock_item', 'Service tidak boleh menjadi stock item.');
            }
        });
    }
}
