<?php

namespace App\Modules\Reports\Requests\Purchase;

use App\Modules\Reports\Requests\Concerns\HasReportDateFilters;
use App\Modules\Reports\Requests\Concerns\HasReportDimensionFilters;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseByProductReportRequest extends FormRequest
{
    use HasReportDateFilters;
    use HasReportDimensionFilters;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->dateFilterRules(),
            ...$this->dimensionFilterRules(),
            'product_id' => ['nullable', 'integer', 'exists:tenant.products,id'],
        ];
    }
}
