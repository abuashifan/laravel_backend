<?php

namespace App\Modules\OpeningBalance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseClearingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `targets` opsional: tanpa itu seluruh saldo perantara ditutup ke akun
     * `opening_balance.equity`. Dengan itu, user memecahnya sendiri ke beberapa
     * akun ekuitas — totalnya diperiksa di service, bukan di sini, karena
     * angka pembandingnya baru diketahui saat saldo perantara dibaca.
     */
    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:255'],
            'targets' => ['nullable', 'array', 'min:1'],
            'targets.*.account_id' => ['required', 'integer', 'min:1'],
            'targets.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
