<?php

namespace App\Modules\Workspace\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DecideWorkspaceBudgetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'note' => [$this->route('decision') === 'reject' ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['note.required' => 'Masukkan alasan penolakan.'];
    }
}
