<?php

namespace App\Http\Requests\Purchase;

class UpdatePurchaseRequestRequest extends StorePurchaseRequestRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['request_date'] = ['sometimes', 'date'];
        $rules['lines'] = ['sometimes', 'array', 'min:1'];

        return $rules;
    }
}
