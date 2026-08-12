<?php

namespace App\Modules\Imports\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profile' => ['required', 'string', Rule::in(array_keys((array) config('imports.profiles', [])))],
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx'],
            'confirm_duplicate_file' => ['sometimes', 'boolean'],
        ];
    }
}
