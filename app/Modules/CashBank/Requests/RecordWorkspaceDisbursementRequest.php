<?php

namespace App\Modules\CashBank\Requests;

use App\Shared\Rules\PostableAccount;
use Illuminate\Foundation\Http\FormRequest;

class RecordWorkspaceDisbursementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'disbursed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'cash_bank_account_id' => ['required', 'integer', 'exists:tenant.chart_of_accounts,id', new PostableAccount],
            'payment_reference' => ['required', 'string', 'max:160'],
            'amount' => ['prohibited'],
        ];
    }
}
