<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StockOpnameActionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return []; }
}

