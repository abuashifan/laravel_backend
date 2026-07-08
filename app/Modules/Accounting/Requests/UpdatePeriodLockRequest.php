<?php

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePeriodLockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lock_until' => ['nullable', 'date'],
            'override_reason' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
