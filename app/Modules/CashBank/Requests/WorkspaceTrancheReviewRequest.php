<?php

namespace App\Modules\CashBank\Requests;

use App\Shared\Rules\PostableAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkspaceTrancheReviewRequest extends FormRequest
{
    public function rules(): array
    {
        $approving = $this->route('decision') === 'approve';

        return [
            'note' => [$approving ? 'nullable' : 'required', 'string', 'max:1000'],
            'lines' => [$approving ? 'nullable' : 'prohibited', 'array', 'list', 'max:100'],
            'lines.*' => ['array:source_line_id,account_id,department_id,project_id'],
            'lines.*.source_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.account_id' => ['required', 'integer', 'exists:tenant.chart_of_accounts,id', new PostableAccount],
            'lines.*.department_id' => ['nullable', 'integer', Rule::exists('tenant.departments', 'id')->where('is_active', true)],
            'lines.*.project_id' => ['nullable', 'integer', Rule::exists('tenant.projects', 'id')->where('is_active', true)->where('status', 'active')],
            'advance_account_id' => [$approving ? 'nullable' : 'prohibited', 'integer', 'exists:tenant.chart_of_accounts,id', new PostableAccount],
            'amount' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['note.required' => 'Masukkan alasan.'];
    }
}
