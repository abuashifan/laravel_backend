<?php

namespace App\Modules\Purchase\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostVendorBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['applied_vendor_deposit_amount' => ['nullable', 'numeric', 'min:0']];
    }
}
