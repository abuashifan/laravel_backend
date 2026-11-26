<?php

namespace App\Shared\Budget;

use App\Shared\Models\Company;

interface WorkspaceBudgetSummaryReader
{
    /** @return array<int, array{limit_minor: string, committed_minor: string}>|null */
    public function totalsByPeriod(Company $company): ?array;
}
