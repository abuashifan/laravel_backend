<?php

namespace App\Services\Budget;

use App\Models\Tenant\BudgetLine;
use App\Models\Tenant\BudgetPeriod;
use App\Models\Tenant\BudgetSubmission;
use App\Services\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

class BudgetConsolidationService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function query(BudgetPeriod $period, array $filters = []): array
    {
        $companyId = $this->tenantContext->companyId();
        $by = $filters['by'] ?? 'department';

        // Approved submission IDs for this period
        $submissionIds = BudgetSubmission::query()
            ->forCompany($companyId)
            ->where('budget_period_id', $period->id)
            ->where('status', 'approved')
            ->pluck('id');

        if ($submissionIds->isEmpty()) {
            return [
                'budget_period' => ['id' => $period->id, 'name' => $period->name, 'fiscal_year' => $period->fiscal_year],
                'breakdown_by' => $by,
                'rows' => [],
                'grand_total' => '0.00',
            ];
        }

        $query = BudgetLine::query()
            ->whereIn('budget_submission_id', $submissionIds)
            ->join('budget_submissions as bs', 'bs.id', '=', 'budget_lines.budget_submission_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'budget_lines.account_id')
            ->leftJoin('projects as proj', 'proj.id', '=', 'budget_lines.project_id')
            ->leftJoin('departments as dept', 'dept.id', '=', 'bs.department_id');

        if (! empty($filters['department_id'])) {
            $query->where('bs.department_id', (int) $filters['department_id']);
        }
        if (! empty($filters['project_id'])) {
            $query->where('budget_lines.project_id', (int) $filters['project_id']);
        }
        if (! empty($filters['account_id'])) {
            $query->where('budget_lines.account_id', (int) $filters['account_id']);
        }

        $rows = match ($by) {
            'project' => $this->groupByProject($query),
            'project_department' => $this->groupByProjectDepartment($query),
            default => $this->groupByDepartment($query),
        };

        $grandTotal = collect($rows)->sum('total_amount');

        return [
            'budget_period' => ['id' => $period->id, 'name' => $period->name, 'fiscal_year' => $period->fiscal_year],
            'breakdown_by' => $by,
            'rows' => $rows,
            'grand_total' => number_format($grandTotal, 2, '.', ''),
        ];
    }

    private function groupByDepartment($query): array
    {
        $lines = (clone $query)
            ->select(
                'bs.department_id',
                DB::raw('MAX(dept.name) as department_name'),
                'budget_lines.account_id',
                DB::raw('MAX(coa.account_name) as account_name'),
                DB::raw('SUM(budget_lines.amount) as total_amount')
            )
            ->groupBy('bs.department_id', 'budget_lines.account_id')
            ->orderBy('bs.department_id')
            ->get();

        return $lines->groupBy('department_id')->map(function ($group, $deptId) {
            return [
                'department_id' => $deptId,
                'department_name' => $group->first()->department_name,
                'accounts' => $group->map(fn ($r) => [
                    'account_id' => $r->account_id,
                    'account_name' => $r->account_name,
                    'total_amount' => number_format((float) $r->total_amount, 2, '.', ''),
                ])->values()->all(),
                'total_amount' => number_format($group->sum('total_amount'), 2, '.', ''),
            ];
        })->values()->all();
    }

    private function groupByProject($query): array
    {
        $lines = (clone $query)
            ->select(
                'budget_lines.project_id',
                DB::raw('MAX(proj.name) as project_name'),
                'budget_lines.account_id',
                DB::raw('MAX(coa.account_name) as account_name'),
                DB::raw('SUM(budget_lines.amount) as total_amount')
            )
            ->groupBy('budget_lines.project_id', 'budget_lines.account_id')
            ->orderBy('budget_lines.project_id')
            ->get();

        return $lines->groupBy('project_id')->map(function ($group, $projectId) {
            return [
                'project_id' => $projectId,
                'project_name' => $group->first()->project_name ?? 'Tanpa Proyek',
                'accounts' => $group->map(fn ($r) => [
                    'account_id' => $r->account_id,
                    'account_name' => $r->account_name,
                    'total_amount' => number_format((float) $r->total_amount, 2, '.', ''),
                ])->values()->all(),
                'total_amount' => number_format($group->sum('total_amount'), 2, '.', ''),
            ];
        })->values()->all();
    }

    private function groupByProjectDepartment($query): array
    {
        $lines = (clone $query)
            ->select(
                'bs.department_id',
                DB::raw('MAX(dept.name) as department_name'),
                'budget_lines.project_id',
                DB::raw('MAX(proj.name) as project_name'),
                'budget_lines.account_id',
                DB::raw('MAX(coa.account_name) as account_name'),
                DB::raw('SUM(budget_lines.amount) as total_amount')
            )
            ->groupBy('bs.department_id', 'budget_lines.project_id', 'budget_lines.account_id')
            ->orderBy('bs.department_id')
            ->get();

        return $lines->groupBy('department_id')->map(function ($deptGroup, $deptId) {
            $byProject = $deptGroup->groupBy('project_id')->map(function ($projGroup, $projectId) {
                return [
                    'project_id' => $projectId,
                    'project_name' => $projGroup->first()->project_name ?? 'Tanpa Proyek',
                    'accounts' => $projGroup->map(fn ($r) => [
                        'account_id' => $r->account_id,
                        'account_name' => $r->account_name,
                        'total_amount' => number_format((float) $r->total_amount, 2, '.', ''),
                    ])->values()->all(),
                    'total_amount' => number_format($projGroup->sum('total_amount'), 2, '.', ''),
                ];
            })->values()->all();

            return [
                'department_id' => $deptId,
                'department_name' => $deptGroup->first()->department_name,
                'projects' => $byProject,
                'total_amount' => number_format($deptGroup->sum('total_amount'), 2, '.', ''),
            ];
        })->values()->all();
    }
}
