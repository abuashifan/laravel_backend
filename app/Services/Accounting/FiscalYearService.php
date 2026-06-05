<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\FiscalYear;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FiscalYearService
{
    public function getActiveFiscalYear(Company $company): ?FiscalYear
    {
        return FiscalYear::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->first();
    }

    public function getOrCreateActiveFiscalYear(Company $company, ?int $year = null): FiscalYear
    {
        $year ??= (int) now()->format('Y');

        $active = $this->getActiveFiscalYear($company);
        if ($active) {
            return $active;
        }

        $fy = $this->createFiscalYear($company, $year);
        $this->createPeriodsForFiscalYear($fy);

        return $fy;
    }

    public function createFiscalYear(
        Company $company,
        int $year,
        ?string $startDate = null,
        ?string $endDate = null
    ): FiscalYear {
        $startDate ??= Carbon::create($year, 1, 1)->toDateString();
        $endDate ??= Carbon::create($year, 12, 31)->toDateString();

        return DB::transaction(function () use ($company, $year, $startDate, $endDate) {
            $previous = FiscalYear::query()
                ->where('company_id', $company->id)
                ->where('year', '<', $year)
                ->orderByDesc('year')
                ->first();

            if ($previous && $previous->status !== 'closed') {
                throw new RuntimeException('Previous fiscal year must be closed before creating a new fiscal year.');
            }

            FiscalYear::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $existing = FiscalYear::query()
                ->where('company_id', $company->id)
                ->where('year', $year)
                ->first();

            if ($existing && $existing->status === 'closed') {
                throw new RuntimeException('Cannot activate a closed fiscal year.');
            }

            return FiscalYear::query()->updateOrCreate(
                ['company_id' => $company->id, 'year' => $year],
                [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => $existing?->status ?? 'open',
                    'is_active' => true,
                ]
            );
        });
    }

    public function createPeriodsForFiscalYear(FiscalYear $fiscalYear): void
    {
        $start = Carbon::parse($fiscalYear->start_date)->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $periodStart = $month->copy()->startOfMonth()->toDateString();
            $periodEnd = $month->copy()->endOfMonth()->toDateString();

            $fiscalYear->periods()->updateOrCreate(
                [
                    'company_id' => $fiscalYear->company_id,
                    'period_year' => (int) $month->format('Y'),
                    'period_month' => (int) $month->format('n'),
                ],
                [
                    'fiscal_year_id' => $fiscalYear->id,
                    'start_date' => $periodStart,
                    'end_date' => $periodEnd,
                    'status' => 'open',
                ]
            );
        }
    }

    public function fiscalYearForDate(Company $company, string $date): ?FiscalYear
    {
        $d = Carbon::parse($date)->toDateString();

        return FiscalYear::query()
            ->where('company_id', $company->id)
            ->where('start_date', '<=', $d)
            ->where('end_date', '>=', $d)
            ->first();
    }

    public function isDateInsideActiveFiscalYear(Company $company, string $date): bool
    {
        $active = $this->getOrCreateActiveFiscalYear($company);
        return $active->containsDate($date);
    }

    public function markClosingRequired(FiscalYear $fiscalYear): FiscalYear
    {
        $fiscalYear->forceFill([
            'status' => 'closing_required',
            'closing_required_at' => $fiscalYear->closing_required_at ?? now(),
        ])->save();

        return $fiscalYear->refresh();
    }

    public function markClosingInProgress(FiscalYear $fiscalYear): FiscalYear
    {
        $fiscalYear->forceFill([
            'status' => 'closing_in_progress',
            'closing_started_at' => $fiscalYear->closing_started_at ?? now(),
        ])->save();

        return $fiscalYear->refresh();
    }

    public function closeFiscalYear(FiscalYear $fiscalYear, ?int $userId = null): FiscalYear
    {
        $fiscalYear->forceFill([
            'status' => 'closed',
            'closed_at' => $fiscalYear->closed_at ?? now(),
            'closed_by' => $userId,
            'is_active' => false,
        ])->save();

        return $fiscalYear->refresh();
    }

    public function canStartNextFiscalYear(Company $company): bool
    {
        $active = $this->getActiveFiscalYear($company);
        if (! $active) {
            return true;
        }

        return $active->status === 'closed';
    }
}
