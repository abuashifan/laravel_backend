<?php

namespace App\Modules\OpeningBalance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetOpeningDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'opening_date' => ['required', 'date'],
        ];
    }
}
