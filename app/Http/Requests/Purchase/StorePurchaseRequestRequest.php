<?php

namespace App\Http\Requests\Purchase;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_date' => ['required', 'date_format:Y-m-d'],
            'needed_date' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                $requestDate = $this->input('request_date');
                if (is_string($requestDate) && is_string($value) && $value < $requestDate) {
                    $fail('The needed date must be a date after or equal to request date.');
                }
            }],
            'requester_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'source_type' => ['nullable', 'string'],
            'source_id' => ['nullable', 'integer'],
            'source_number' => ['nullable', 'string'],
            'source_revision' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.product_code' => ['nullable', 'string', 'max:100'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.warehouse_id' => ['nullable', 'integer'],
            'lines.*.department_id' => ['nullable', 'integer'],
            'lines.*.project_id' => ['nullable', 'integer'],
            'lines.*.source_line_type' => ['nullable', 'string'],
            'lines.*.source_line_id' => ['nullable', 'integer'],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }
}
