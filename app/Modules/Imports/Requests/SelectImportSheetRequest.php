<?php

namespace App\Modules\Imports\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SelectImportSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sheet' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'sheet.required' => 'Sheet wajib dipilih.',
            'sheet.string' => 'Nama sheet tidak valid.',
            'sheet.max' => 'Nama sheet maksimal 255 karakter.',
        ];
    }
}
