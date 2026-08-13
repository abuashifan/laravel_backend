<?php

namespace App\Modules\Sales\Requests;

use App\Shared\Rules\PostableAccount;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['deposit_date' => ['required', 'date'], 'customer_id' => ['required', 'exists:tenant.contacts,id'], 'sales_order_id' => ['nullable', 'integer'], 'cash_bank_account_id' => ['required', 'integer', 'exists:tenant.chart_of_accounts,id', new PostableAccount], 'amount' => ['required', 'numeric', 'gt:0'], 'notes' => ['nullable', 'string']];
    }
}
