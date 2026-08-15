<?php

namespace App\Modules\Budget\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // NULL = anggaran tingkat perusahaan yang diajukan Finance tanpa
            // melewati tahap kepala departemen.
            'department_id' => ['nullable', 'integer', 'exists:tenant.departments,id'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
