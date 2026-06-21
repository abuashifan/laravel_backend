<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountMappingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mappings' => ['required', 'array', 'min:1'],
            'mappings.*.mapping_key' => ['required', 'string'],
            'mappings.*.account_id' => ['nullable', 'integer'],
        ];
    }
}
