<?php

namespace App\Modules\Reports\Requests;

use App\Modules\Reports\Requests\Concerns\HasReportDimensionFilters;
use Illuminate\Foundation\Http\FormRequest;

class MultiPeriodReportRequest extends FormRequest
{
    use HasReportDimensionFilters;

    /** Batas jumlah kolom periode agar tidak membebani (mis. 12 bulan). */
    private const MAX_PERIODS = 12;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'periods' => ['required', 'array', 'min:1', 'max:'.self::MAX_PERIODS],
            'periods.*.start_date' => ['required', 'date'],
            'periods.*.end_date' => ['required', 'date'],
            'periods.*.label' => ['nullable', 'string', 'max:60'],
            ...$this->dimensionFilterRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'periods.max' => 'Maksimal '.self::MAX_PERIODS.' periode per permintaan.',
        ];
    }
}
