<?php

namespace App\Modules\Reports\Requests\Tax;

use App\Modules\Reports\Requests\Concerns\HasReportDateFilters;
use Illuminate\Foundation\Http\FormRequest;

class EfakturExportRequest extends FormRequest
{
    use HasReportDateFilters;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->dateFilterRules(),
        ];
    }
}
