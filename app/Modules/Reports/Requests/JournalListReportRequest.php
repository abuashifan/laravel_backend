<?php

namespace App\Modules\Reports\Requests;

use App\Modules\Reports\Requests\Concerns\HasReportDateFilters;
use Illuminate\Foundation\Http\FormRequest;

class JournalListReportRequest extends FormRequest
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
            'source' => ['nullable', 'in:all,sales,purchase,general'],
        ];
    }
}
