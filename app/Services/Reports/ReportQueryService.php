<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportDateRange;
use App\Data\Reports\ReportDimensionFilter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportQueryService
{
    public function __construct(private readonly ?ReportVisibilityService $visibilityService = null)
    {
    }

    public function reportableJournalLinesQuery(): Builder
    {
        $query = DB::connection('tenant')->table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', '=', 'posted')
            ->where('je.is_obsolete', '=', 0);

        if ($this->visibilityService) {
            $query->whereIn('je.status', (array) config('report_visibility.reportable_journal_statuses', ['posted']));
        }

        return $query;
    }

    public function applyDateRange(Builder $query, ReportDateRange $dateRange, bool $opening = false): Builder
    {
        $range = $dateRange->normalizeToDateString();

        if ($opening) {
            if ($range->startDate) {
                return $query->whereDate('je.journal_date', '<', $range->startDate);
            }
            return $query;
        }

        if ($range->startDate) {
            $query->whereDate('je.journal_date', '>=', $range->startDate);
        }
        if ($range->endDate) {
            $query->whereDate('je.journal_date', '<=', $range->endDate);
        }

        return $query;
    }

    public function applyDimensionFilter(Builder $query, ReportDimensionFilter $dimensionFilter): Builder
    {
        if ($dimensionFilter->departmentId !== null) {
            $query->where('jel.department_id', '=', $dimensionFilter->departmentId);
        }
        if ($dimensionFilter->projectId !== null) {
            $query->where('jel.project_id', '=', $dimensionFilter->projectId);
        }

        return $query;
    }

    public function applyAccountFilter(Builder $query, ?int $accountId): Builder
    {
        if ($accountId) {
            $query->where('jel.account_id', '=', $accountId);
        }
        return $query;
    }

    public function applyAccountTypeFilter(Builder $query, ?string $accountType): Builder
    {
        if ($accountType) {
            $query->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
                ->where('coa.account_type', '=', $accountType);
        }
        return $query;
    }
}
