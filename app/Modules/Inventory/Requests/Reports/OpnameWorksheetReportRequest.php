<?php

namespace App\Modules\Inventory\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class OpnameWorksheetReportRequest extends FormRequest
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
            'opname_id' => ['nullable', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:draft,counting,counted,finalized,void'],
        ];
    }
}
