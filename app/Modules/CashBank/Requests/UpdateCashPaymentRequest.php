<?php

namespace App\Modules\CashBank\Requests;

use Illuminate\Validation\Rule;

/**
 * Edit kas keluar: aturan isian sama dengan buat baru, kecuali nomor
 * dokumen boleh tetap nomor miliknya sendiri.
 */
class UpdateCashPaymentRequest extends StoreCashPaymentRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['payment_number'] = ['nullable', 'string', 'max:190', Rule::unique('tenant.cash_payments', 'payment_number')->ignore((int) $this->route('id'))];

        return $rules;
    }
}
