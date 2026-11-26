<?php

namespace App\Modules\CashBank\Requests;

use App\Shared\Rules\PostableAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Penyelesaian dana muka: biaya terverifikasi per baris dan akun pengembalian sisa. */
class WorkspaceSettlementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'settled_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'refund_cash_bank_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id', new PostableAccount],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['present', 'array', 'list', 'max:100'],
            'lines.*' => ['array:source_line_id,amount,account_id,department_id,project_id'],
            'lines.*.source_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:tenant.chart_of_accounts,id', new PostableAccount],
            'lines.*.department_id' => ['nullable', 'integer', Rule::exists('tenant.departments', 'id')->where('is_active', true)],
            'lines.*.project_id' => ['nullable', 'integer', Rule::exists('tenant.projects', 'id')->where('is_active', true)->where('status', 'active')],
        ];
    }
}
