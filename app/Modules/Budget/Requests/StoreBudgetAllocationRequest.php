<?php

namespace App\Modules\Budget\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // NULL = pagu tingkat perusahaan (root).
            'department_id' => ['nullable', 'integer', 'exists:tenant.departments,id'],
            // Wajib diisi untuk pagu departemen (menunjuk pagu perusahaan);
            // wajib KOSONG untuk pagu perusahaan itu sendiri — ditegakkan di
            // service (BudgetAllocationService::assertValidParent), bukan di
            // sini, karena aturannya bersyarat pada `department_id`.
            'parent_allocation_id' => ['nullable', 'integer', 'exists:tenant.budget_allocations,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
