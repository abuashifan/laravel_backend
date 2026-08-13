<?php

namespace App\Modules\CashBank\Services;

use App\Modules\MasterData\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Collection;

class CashBankAccountService
{
    /**
     * Akun yang boleh dipakai sebagai akun kas/bank di transaksi: efektif
     * cash/bank (lihat `ChartOfAccount::effectiveCashBankIds()`) DAN postable
     * (bukan akun induk). Akun induk cuma untuk rekap saldo di laporan.
     *
     * @return Collection<int,ChartOfAccount>
     */
    public function getCashBankAccounts(bool $includeInactive = false): Collection
    {
        $q = ChartOfAccount::query()
            ->whereIn('id', ChartOfAccount::effectiveCashBankIds())
            ->whereDoesntHave('children');

        if (! $includeInactive) {
            $q->where('is_active', true);
        }

        return $q->orderBy('account_code')->get();
    }

    public function isCashBankAccount(int $accountId): bool
    {
        return ChartOfAccount::query()
            ->whereKey($accountId)
            ->whereIn('id', ChartOfAccount::effectiveCashBankIds())
            ->whereDoesntHave('children')
            ->exists();
    }
}
