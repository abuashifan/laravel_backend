<?php

namespace App\Modules\Companies\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirm_name' => ['required', 'string'],
        ];
    }
}
