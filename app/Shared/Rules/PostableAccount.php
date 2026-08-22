<?php

namespace App\Shared\Rules;

use App\Modules\MasterData\Models\ChartOfAccount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Akun induk (yang punya akun anak) tidak boleh dipakai untuk transaksi --
 * akun induk hanya untuk merangkum saldo akun anaknya di laporan. Pasang
 * setelah rule `exists`; kalau akunnya sendiri tidak ditemukan, rule ini
 * diam supaya pesan `exists` yang tampil, bukan dobel.
 */
class PostableAccount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $account = ChartOfAccount::query()->find($value);
        if (! $account) {
            return;
        }

        if ($account->children()->exists()) {
            $fail("Akun '{$account->account_name}' adalah akun induk dan hanya untuk rekap saldo -- pilih akun anak (leaf) untuk transaksi.");
        }
    }
}
