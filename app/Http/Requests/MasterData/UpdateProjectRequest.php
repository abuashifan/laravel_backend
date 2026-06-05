<?php

namespace App\Http\Requests\MasterData;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                $startDate = $this->input('start_date');
                if (is_string($startDate) && is_string($value) && $value < $startDate) {
                    $fail('The end date must be a date after or equal to start date.');
                }
            }],
            'status' => ['nullable', 'in:active,completed,on_hold,cancelled'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
