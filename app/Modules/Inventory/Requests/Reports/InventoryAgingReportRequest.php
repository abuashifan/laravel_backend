<?php

namespace App\Modules\Inventory\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class InventoryAgingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'as_of_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'include_zero' => ['nullable', 'boolean'],
            'include_negative' => ['nullable', 'boolean'],
        ];
    }
}
