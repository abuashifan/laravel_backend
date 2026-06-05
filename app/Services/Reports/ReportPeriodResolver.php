<?php

namespace App\Services\Reports;

use App\Services\Accounting\FiscalYearService;
use App\Services\Tenant\TenantContext;
use Carbon\Carbon;

class ReportPeriodResolver
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ?FiscalYearService $fiscalYearService = null,
    ) {
    }

    public function fiscalYearForRange(?string $startDate, ?string $endDate): ?array
    {
        if (! $this->fiscalYearService) {
            return null;
        }

        $company = $this->tenantContext->company();
        if (! $company) {
            return null;
        }

        try {
            $probe = $startDate ?: $endDate;
            if (! $probe) {
                return null;
            }

            $fy = $this->fiscalYearService->fiscalYearForDate($company, Carbon::parse($probe)->toDateString());
            if (! $fy) {
                return null;
            }

            return [
                'id' => (int) $fy->id,
                'year' => (int) $fy->year,
                'start_date' => (string) $fy->start_date,
                'end_date' => (string) $fy->end_date,
                'status' => (string) $fy->status,
                'is_active' => (bool) $fy->is_active,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function activeFiscalYearMeta(): ?array
    {
        if (! $this->fiscalYearService) {
            return null;
        }

        $company = $this->tenantContext->company();
        if (! $company) {
            return null;
        }

        try {
            $fy = $this->fiscalYearService->getActiveFiscalYear($company);
            if (! $fy) {
                return null;
            }

            return [
                'id' => (int) $fy->id,
                'year' => (int) $fy->year,
                'start_date' => (string) $fy->start_date,
                'end_date' => (string) $fy->end_date,
                'status' => (string) $fy->status,
                'is_active' => (bool) $fy->is_active,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function reportPeriodLabel(?string $startDate, ?string $endDate): string
    {
        $start = $startDate ? Carbon::parse($startDate)->toDateString() : null;
        $end = $endDate ? Carbon::parse($endDate)->toDateString() : null;

        if ($start && $end) {
            return $start.' to '.$end;
        }
        if ($start) {
            return 'from '.$start;
        }
        if ($end) {
            return 'until '.$end;
        }
        return 'all time';
    }
}

