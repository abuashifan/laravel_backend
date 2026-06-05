<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Validation\Validator;

class UpdatePaymentTermRequest extends StorePaymentTermRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['code'] = ['sometimes', 'string', 'max:30'];
        $rules['name'] = ['sometimes', 'string', 'max:255'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('is_custom') && ! $this->boolean('is_custom') && $this->input('days') === null) {
                $validator->errors()->add('days', 'Days is required unless payment term is custom.');
            }
        });
    }
}
