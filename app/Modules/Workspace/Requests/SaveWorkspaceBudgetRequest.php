<?php

namespace App\Modules\Workspace\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveWorkspaceBudgetRequest extends FormRequest
{
    public function rules(): array
    {
        $annual = $this->input('kind') === 'annual';

        return [
            'kind' => ['required', Rule::in(['annual', 'activity'])],
            'period_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'unit_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:160'],
            'activity_date' => [$annual ? 'prohibited' : 'required', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'lines.*' => ['array:code,item_id,description,amount'],
            'lines.*.code' => [$annual ? 'required' : 'prohibited', 'string', 'distinct:ignore_case', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,23}$/'],
            'lines.*.item_id' => [$annual ? 'prohibited' : 'required', 'integer', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:120'],
            'lines.*.amount' => ['required', 'regex:/^[0-9]{1,11}(\.[0-9]{1,2})?$/', 'numeric', 'min:0.01'],
        ];
    }
}
