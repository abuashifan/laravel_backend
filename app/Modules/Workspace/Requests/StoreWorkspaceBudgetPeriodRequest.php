<?php

namespace App\Modules\Workspace\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkspaceBudgetPeriodRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'period_from' => ['required', 'date_format:Y-m-d', 'regex:/^[0-9]{4}-[0-9]{2}-01$/', 'after_or_equal:1900-01-01', 'before:9999-01-01'],
        ];
    }

    public function messages(): array
    {
        return ['period_from.regex' => 'Awal periode harus tanggal 1 pada bulan yang dipilih.'];
    }
}
