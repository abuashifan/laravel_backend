<?php

namespace App\Modules\Workspace\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveWorkspaceUnitRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $code = Rule::unique('workspace.workspace_units', 'code');
        if ($this->route('unit') !== null) {
            $code->ignore((int) $this->route('unit'));
        }

        return [
            'code' => ['required', 'string', 'max:24', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', $code],
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
