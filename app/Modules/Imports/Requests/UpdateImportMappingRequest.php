<?php

namespace App\Modules\Imports\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateImportMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'column_map' => ['required', 'array', 'min:1'],
            'column_map.*' => ['required', 'string', 'max:255'],
        ];
    }
}
