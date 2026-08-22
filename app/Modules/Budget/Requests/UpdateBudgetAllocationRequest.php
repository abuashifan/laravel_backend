<?php

namespace App\Modules\Budget\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `department_id` dan `parent_allocation_id` sengaja tidak bisa
            // diubah lewat update — memindahkan pagu ke induk/departemen lain
            // adalah perubahan struktural, bukan revisi nominal. Buat pagu baru
            // kalau strukturnya perlu berubah.
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
