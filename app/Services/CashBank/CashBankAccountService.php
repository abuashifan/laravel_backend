<?php

namespace App\Services\CashBank;

use App\Models\Tenant\ChartOfAccount;
use Illuminate\Database\Eloquent\Collection;

class CashBankAccountService
{
    /**
     * @return Collection<int,ChartOfAccount>
     */
    public function getCashBankAccounts(bool $includeInactive = false): Collection
    {
        $q = ChartOfAccount::query()
            ->where('is_cash_bank', true);

        if (! $includeInactive) {
            $q->where('is_active', true);
        }

        return $q->orderBy('account_code')->get();
    }

    public function isCashBankAccount(int $accountId): bool
    {
        return ChartOfAccount::query()
            ->whereKey($accountId)
            ->where('is_cash_bank', true)
            ->exists();
    }
}

