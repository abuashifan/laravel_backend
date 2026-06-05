<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_code' => ['nullable', 'string', 'max:50'],
            'product_name' => ['required', 'string', 'max:255'],
            'product_type' => ['nullable', 'in:goods,service,non_inventory'],
            'product_category_id' => ['nullable', 'integer'],
            'unit_id' => ['nullable', 'integer'],
            'is_stock_item' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],
            'sales_account_id' => ['nullable', 'integer'],
            'purchase_account_id' => ['nullable', 'integer'],
            'inventory_account_id' => ['nullable', 'integer'],
            'cogs_account_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $isStockItem = (bool) $this->boolean('is_stock_item');
            $unitId = $this->input('unit_id');

            if ($isStockItem && empty($unitId)) {
                $validator->errors()->add('unit_id', 'unit_id wajib untuk stock item.');
            }

            $type = $this->input('product_type', 'goods');
            if ($type === 'service' && $isStockItem) {
                $validator->errors()->add('is_stock_item', 'Service tidak boleh menjadi stock item.');
            }
        });
    }
}

