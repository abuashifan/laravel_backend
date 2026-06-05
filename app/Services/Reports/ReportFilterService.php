<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportDateRange;
use App\Data\Reports\ReportDimensionFilter;
use App\Models\Tenant\Department;
use App\Models\Tenant\Project;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class ReportFilterService
{
    public function normalizeDateRange(array $input): ReportDateRange
    {
        return ReportDateRange::fromArray($input);
    }

    public function normalizeDimensions(array $input): ReportDimensionFilter
    {
        return ReportDimensionFilter::fromArray($input);
    }

    /**
     * @return array{valid:bool,errors:array}
     */
    public function validateDateRange(ReportDateRange $dateRange): array
    {
        $errors = [];

        try {
            $normalized = $dateRange->normalizeToDateString();
            if ($normalized->startDate && $normalized->endDate) {
                if (Carbon::parse($normalized->endDate)->lt(Carbon::parse($normalized->startDate))) {
                    $errors['end_date'][] = 'end_date must be after or equal to start_date.';
                }
            }
        } catch (\Throwable $e) {
            $errors['date'][] = 'Invalid date range.';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * @return array{valid:bool,errors:array}
     */
    public function validateDimensions(ReportDimensionFilter $dimensions): array
    {
        $errors = [];

        if ($dimensions->departmentId !== null) {
            if (! $this->hasDepartmentSupport()) {
                $errors['department_id'][] = 'Department filter is not supported in this environment.';
            } elseif (! Department::query()->whereKey($dimensions->departmentId)->exists()) {
                $errors['department_id'][] = 'Department not found.';
            }
        }

        if ($dimensions->projectId !== null) {
            if (! $this->hasProjectSupport()) {
                $errors['project_id'][] = 'Project filter is not supported in this environment.';
            } elseif (! Project::query()->whereKey($dimensions->projectId)->exists()) {
                $errors['project_id'][] = 'Project not found.';
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * @return array{valid:bool,errors:array}
     */
    public function validateAccountType(?string $accountType): array
    {
        $errors = [];

        if ($accountType === null || $accountType === '') {
            return ['valid' => true, 'errors' => []];
        }

        $allowed = ['asset', 'liability', 'equity', 'revenue', 'expense'];
        if (! in_array($accountType, $allowed, true)) {
            $errors['account_type'][] = 'Invalid account_type.';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function buildCommonFilters(array $input): array
    {
        $dateRange = $this->normalizeDateRange($input)->normalizeToDateString();
        $dimensions = $this->normalizeDimensions($input);

        return [
            ...$dateRange->toArray(),
            ...$dimensions->toArray(),
            'include_zero_balance' => (bool) ($input['include_zero_balance'] ?? false),
            'include_inactive_accounts' => (bool) ($input['include_inactive_accounts'] ?? false),
            'account_type' => $input['account_type'] ?? null,
            'sort_by' => $input['sort_by'] ?? null,
            'sort_direction' => $input['sort_direction'] ?? null,
        ];
    }

    private function hasDepartmentSupport(): bool
    {
        return Schema::connection('tenant')->hasTable('departments')
            && Schema::connection('tenant')->hasColumn('journal_entry_lines', 'department_id');
    }

    private function hasProjectSupport(): bool
    {
        return Schema::connection('tenant')->hasTable('projects')
            && Schema::connection('tenant')->hasColumn('journal_entry_lines', 'project_id');
    }
}

