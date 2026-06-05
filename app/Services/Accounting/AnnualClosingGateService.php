<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\FiscalYear;
use Carbon\Carbon;

class AnnualClosingGateService
{
    public function __construct(private readonly FiscalYearService $fiscalYearService)
    {
    }

    public function closingRequired(Company $company, ?string $currentDate = null): bool
    {
        $currentDate ??= now()->toDateString();

        $active = $this->fiscalYearService->getOrCreateActiveFiscalYear($company);
        if ($active->status === 'closed') {
            return false;
        }

        return Carbon::parse($currentDate)->greaterThan(Carbon::parse($active->end_date));
    }

    public function blockingFiscalYear(Company $company, ?string $transactionDate = null): ?FiscalYear
    {
        if (! $transactionDate) {
            return null;
        }

        $active = $this->fiscalYearService->getOrCreateActiveFiscalYear($company);

        $tx = Carbon::parse($transactionDate)->startOfDay();
        $activeEnd = Carbon::parse($active->end_date)->endOfDay();

        if ($tx->greaterThan($activeEnd) && $active->status !== 'closed') {
            return $active;
        }

        return null;
    }

    public function canEnterDate(Company $company, string $transactionDate): bool
    {
        return $this->blockingFiscalYear($company, $transactionDate) === null;
    }

    public function blockingReason(Company $company, string $transactionDate): ?string
    {
        $blocking = $this->blockingFiscalYear($company, $transactionDate);
        if ($blocking) {
            return 'PREVIOUS_FISCAL_YEAR_NOT_CLOSED';
        }

        return null;
    }
}

