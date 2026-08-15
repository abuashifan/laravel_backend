<?php

namespace App\Modules\Budget\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Nullable — kosong berarti di-generate BudgetPeriodService dari
            // fiscal_year (form gabungan pagu+periode tidak mewajibkan nama).
            'name' => ['nullable', 'string', 'max:255'],
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            // Referensi lunak ke fiscal_years (central DB) — sengaja tanpa
            // exists: rule, tabelnya tidak hidup di koneksi tenant.
            'fiscal_year_id' => ['nullable', 'integer'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            // Pagu per departemen, dibuat atomik bersama periode ini — lihat
            // BudgetPeriodService::createWithAllocations().
            'department_allocations' => ['nullable', 'array'],
            'department_allocations.*.department_id' => ['required', 'integer', 'distinct', 'exists:tenant.departments,id'],
            'department_allocations.*.amount' => ['required', 'numeric', 'min:0'],
            'department_allocations.*.notes' => ['nullable', 'string'],
        ];
    }
}
