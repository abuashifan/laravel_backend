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

    public function messages(): array
    {
        return [
            'column_map.required' => 'Pemetaan kolom wajib diisi.',
            'column_map.array' => 'Pemetaan kolom harus berupa daftar.',
            'column_map.min' => 'Minimal satu kolom harus dipetakan.',
            'column_map.*.required' => 'Setiap kolom yang dipetakan harus diisi.',
            'column_map.*.string' => 'Nama header kolom tidak valid.',
            'column_map.*.max' => 'Nama header kolom maksimal 255 karakter.',
        ];
    }
}
