<?php

namespace App\Modules\Purchase\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseRequestActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
