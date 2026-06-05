<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class VoidStockOpnameRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['reason' => ['required', 'string', 'min:3', 'max:1000']]; }
}
