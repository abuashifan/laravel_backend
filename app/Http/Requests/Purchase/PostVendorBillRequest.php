<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class PostVendorBillRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['applied_vendor_deposit_amount' => ['nullable', 'numeric', 'min:0']];
    }
}
