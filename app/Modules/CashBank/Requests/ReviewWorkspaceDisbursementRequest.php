<?php

namespace App\Modules\CashBank\Requests;

use App\Shared\Rules\PostableAccount;
use Illuminate\Foundation\Http\FormRequest;

class ReviewWorkspaceDisbursementRequest extends FormRequest
{
    public function rules(): array
    {
        $approving = $this->route('decision') === 'approve';

        return [
            'note' => [$approving ? 'nullable' : 'required', 'string', 'max:1000'],
            'lines' => [$approving ? 'required' : 'prohibited', 'array', 'list', 'min:1', 'max:100'],
            'lines.*' => ['array:source_line_id,account_id,department_id,project_id'],
            'lines.*.source_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.account_id' => ['required', 'integer', 'exists:tenant.chart_of_accounts,id', new PostableAccount],
            'lines.*.department_id' => ['nullable', 'integer', 'exists:tenant.departments,id'],
            'lines.*.project_id' => ['nullable', 'integer', 'exists:tenant.projects,id'],
            'amount' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['note.required' => 'Masukkan alasan pengembalian QC.'];
    }
}
