<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePaymentTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_custom' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('is_custom') && $this->input('days') === null) {
                $validator->errors()->add('days', 'Days is required unless payment term is custom.');
            }
        });
    }
}
