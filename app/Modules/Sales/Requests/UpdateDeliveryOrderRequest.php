<?php

namespace App\Modules\Sales\Requests;

class UpdateDeliveryOrderRequest extends StoreDeliveryOrderRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['customer_id'] = ['sometimes', 'exists:tenant.contacts,id'];
        $rules['delivery_date'] = ['sometimes', 'date'];
        $rules['lines'] = ['sometimes', 'array', 'min:1'];

        return $rules;
    }
}
