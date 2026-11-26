<?php

namespace App\Modules\CashBank\Requests;

use App\Shared\Rules\PostableAccount;
use Illuminate\Foundation\Http\FormRequest;

/** Catat atau koreksi satu tahap pencairan: nominal per baris RAB, bukan nominal bebas. */
class WorkspaceTrancheRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:direct,advance'],
            'disbursed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'cash_bank_account_id' => ['required', 'integer', 'exists:tenant.chart_of_accounts,id', new PostableAccount],
            'payment_reference' => ['required', 'string', 'max:160'],
            'lines' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'lines.*' => ['array:source_line_id,amount'],
            'lines.*.source_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'amount' => ['prohibited'],
        ];
    }
}
