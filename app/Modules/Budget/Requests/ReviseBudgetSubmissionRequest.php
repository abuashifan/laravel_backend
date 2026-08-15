<?php

namespace App\Modules\Budget\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviseBudgetSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Alasan revisi wajib dan tidak boleh sekadar "revisi" — inilah satu-
            // satunya penjelasan kenapa angka anggaran berubah antar versi.
            'revision_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'revision_reason.required' => 'Alasan revisi wajib diisi.',
            'revision_reason.min' => 'Alasan revisi minimal 10 karakter — jelaskan apa yang berubah dan mengapa.',
        ];
    }
}
