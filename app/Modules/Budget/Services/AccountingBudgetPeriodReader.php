<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\BudgetPeriod;
use App\Shared\Budget\BudgetPeriodOverlapReader;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantProductService;

class AccountingBudgetPeriodReader implements BudgetPeriodOverlapReader
{
    public function __construct(
        private readonly TenantProductService $products,
        private readonly TenantConnectionManager $connections,
    ) {}

    public function overlaps(Company $company, string $from, string $to): bool
    {
        $accounting = $this->products->find($company, TenantDatabase::PRODUCT_ACCOUNTING);
        if ($accounting === null) {
            return false;
        }

        $this->connections->connect($accounting);
        try {
            return BudgetPeriod::query()->forCompany($company->id)
                ->whereDate('period_from', '<=', $to)->whereDate('period_to', '>=', $from)->exists();
        } finally {
            $this->connections->disconnect();
        }
    }
}
