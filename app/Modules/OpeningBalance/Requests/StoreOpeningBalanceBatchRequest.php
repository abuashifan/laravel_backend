<?php

namespace App\Modules\OpeningBalance\Requests;

use App\Modules\OpeningBalance\Support\OpeningBalanceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpeningBalanceBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'opening_date' => ['required', 'date'],
            'fiscal_year' => ['nullable', 'integer', 'min:1900', 'max:2200'],
            'type' => ['nullable', 'string', Rule::in(OpeningBalanceType::all())],
            'description' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
