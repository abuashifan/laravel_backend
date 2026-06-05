<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class AllocateCustomerDepositRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['amount' => ['required', 'numeric', 'gt:0']]; }
}
