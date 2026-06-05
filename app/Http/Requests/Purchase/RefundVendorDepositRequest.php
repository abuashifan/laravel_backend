<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class RefundVendorDepositRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['nullable', 'string']];
    }
}
