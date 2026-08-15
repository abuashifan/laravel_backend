<?php

namespace App\Modules\Budget\Requests;

use App\Shared\Rules\PostableAccount;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array'],
            'lines.*.account_id' => ['required', 'integer', 'exists:tenant.chart_of_accounts,id', new PostableAccount],
            // Dimensi baris. Kalau key-nya tidak dikirim sama sekali, service
            // mewarisi departemen pemilik dokumen; kirim `null` eksplisit untuk
            // baris yang memang lintas departemen.
            'lines.*.department_id' => ['nullable', 'integer', 'exists:tenant.departments,id'],
            'lines.*.project_id' => ['nullable', 'integer', 'exists:tenant.projects,id'],
            // `period` adalah nama lama dari `period_month`; keduanya diterima
            // supaya pemanggil lama tidak rusak (lihat BudgetSubmissionService).
            'lines.*.period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'lines.*.period_month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'lines.*.amount' => ['required', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }
}
