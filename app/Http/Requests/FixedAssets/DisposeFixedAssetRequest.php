<?php

namespace App\Http\Requests\FixedAssets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DisposeFixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'disposal_date' => ['required', 'date_format:Y-m-d'],
            'disposal_type' => ['required', 'in:sale,write_off,scrap,lost'],
            'disposed_quantity' => ['required', 'numeric', 'gt:0'],
            'proceeds_amount' => ['nullable', 'numeric', 'min:0'],
            'cash_bank_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'receivable_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $proceeds = (float) ($this->input('proceeds_amount') ?? 0);
            $hasCashBank = filled($this->input('cash_bank_account_id'));
            $hasReceivable = filled($this->input('receivable_account_id'));

            if ($proceeds > 0 && $hasCashBank === $hasReceivable) {
                $validator->errors()->add('cash_bank_account_id', 'Pilih tepat satu akun penerimaan jika proceeds diisi.');
                $validator->errors()->add('receivable_account_id', 'Pilih tepat satu akun penerimaan jika proceeds diisi.');
            }

            if ($proceeds <= 0 && ($hasCashBank || $hasReceivable)) {
                $validator->errors()->add('proceeds_amount', 'Akun penerimaan hanya boleh diisi jika proceeds ada.');
            }
        });
    }
}
