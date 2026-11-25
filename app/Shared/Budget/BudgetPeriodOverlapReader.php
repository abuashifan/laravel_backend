<?php

namespace App\Shared\Budget;

use App\Shared\Models\Company;

interface BudgetPeriodOverlapReader
{
    public function overlaps(Company $company, string $from, string $to): bool;
}
