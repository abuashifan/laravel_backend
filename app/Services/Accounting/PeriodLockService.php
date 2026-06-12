<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\FiscalYear;
use Carbon\Carbon;

class PeriodLockService
{
    public function __construct(private readonly FiscalYearService $fiscalYearService)
    {
    }

    public function isFiscalYearClosed(Company $company, string $date): bool
    {
        $fy = $this->fiscalYearService->fiscalYearForDate($company, $date);
        return $fy instanceof FiscalYear && ($fy->status === 'closed' || (bool) ($fy->is_closed ?? false));
    }

    public function isLockedUntil(Company $company, string $date): bool
    {
        $fy = $this->fiscalYearService->fiscalYearForDate($company, $date);
        if (! ($fy instanceof FiscalYear)) {
            return false;
        }

        $lockedUntil = $fy->locked_until ?? null;
        if (! $lockedUntil) {
            return false;
        }

        $d = Carbon::parse($date)->toDateString();
        $u = Carbon::parse($lockedUntil)->toDateString();

        return $d <= $u;
    }

    public function isPeriodClosed(Company $company, string $date): bool
    {
        $d = Carbon::parse($date)->toDateString();

        $period = $company->accountingPeriods()
            ->where('start_date', '<=', $d)
            ->where('end_date', '>=', $d)
            ->first();

        return $period?->status === 'closed';
    }

    public function isDateReadOnly(Company $company, string $date): bool
    {
        if ($this->isFiscalYearClosed($company, $date)) {
            return true;
        }

        if ($this->isPeriodClosed($company, $date)) {
            return true;
        }

        return $this->isLockedUntil($company, $date);
    }

    public function blockingReasonForDate(Company $company, string $date): ?string
    {
        if ($this->isFiscalYearClosed($company, $date)) {
            return 'FISCAL_YEAR_CLOSED';
        }

        if ($this->isPeriodClosed($company, $date)) {
            return 'PERIOD_LOCKED';
        }

        if ($this->isLockedUntil($company, $date)) {
            return 'PERIOD_LOCKED';
        }

        return null;
    }
}
