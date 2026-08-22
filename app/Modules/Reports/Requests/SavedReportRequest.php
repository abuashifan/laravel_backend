<?php

namespace App\Modules\Reports\Requests;

use App\Modules\Reports\Models\SavedReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavedReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'report_key' => ['required', 'string', Rule::in(SavedReport::ALLOWED_REPORT_KEYS)],
            'name' => ['required', 'string', 'max:100'],
            'params' => ['nullable', 'array'],
            'shared_user_ids' => ['sometimes', 'array'],
            'shared_user_ids.*' => ['integer', 'min:1'],
        ];
    }
}
