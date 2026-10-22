<?php

namespace App\Modules\CashBank\Requests;

use Illuminate\Validation\Rule;

/**
 * Edit kas masuk: aturan isian sama dengan buat baru, kecuali nomor
 * dokumen boleh tetap nomor miliknya sendiri.
 */
class UpdateCashReceiptRequest extends StoreCashReceiptRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['receipt_number'] = ['nullable', 'string', 'max:190', Rule::unique('tenant.cash_receipts', 'receipt_number')->ignore((int) $this->route('id'))];

        return $rules;
    }
}
