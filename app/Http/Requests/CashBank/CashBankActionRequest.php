<?php

namespace App\Http\Requests\CashBank;

use Illuminate\Foundation\Http\FormRequest;

class CashBankActionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['reason' => ['nullable', 'string', 'max:1000']]; }
}
