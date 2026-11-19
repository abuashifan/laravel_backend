<?php

namespace App\Modules\Purchase\Requests;

use App\Shared\Rules\PostableAccount;
use Illuminate\Foundation\Http\FormRequest;

class VendorPaymentCashBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cash_bank_account_id' => ['required', 'integer', 'exists:tenant.chart_of_accounts,id', new PostableAccount],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'payment_id' => ['nullable', 'integer', 'exists:tenant.vendor_payments,id'],
        ];
    }
}
