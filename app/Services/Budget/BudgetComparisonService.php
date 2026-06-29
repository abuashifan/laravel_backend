<?php

namespace App\Services\Budget;

use App\Models\Tenant\BudgetLine;
use App\Models\Tenant\BudgetPeriod;
use App\Models\Tenant\BudgetSubmission;
use App\Services\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

class BudgetComparisonService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function compare(array $filters): array
    {
        $companyId = $this->tenantContext->companyId();
        $periodId = (int) $filters['budget_period_id'];

        $period = BudgetPeriod::query()
            ->forCompany($companyId)
            ->findOrFail($periodId);

        $submissionIds = BudgetSubmission::query()
            ->forCompany($companyId)
            ->where('budget_period_id', $period->id)
            ->where('status', 'approved')
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('department_id', (int) $filters['department_id']))
            ->pluck('id');

        $budgetQuery = BudgetLine::query()
            ->whereIn('budget_submission_id', $submissionIds)
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'budget_lines.account_id')
            ->when(! empty($filters['project_id']), fn ($q) => $q->where('project_id', (int) $filters['project_id']))
            ->select(
                'budget_lines.account_id',
                DB::raw('MAX(coa.name) as account_name'),
                DB::raw('MAX(coa.code) as account_code'),
                DB::raw('SUM(budget_lines.amount) as budget_amount')
            )
            ->groupBy('budget_lines.account_id');

        $budgetRows = $budgetQuery->get()->keyBy('account_id');

        $jeQuery = DB::connection('tenant')
            ->table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.journal_date', [$period->period_from, $period->period_to]);

        if (! empty($filters['department_id'])) {
            $jeQuery->where('jel.department_id', (int) $filters['department_id']);
        }
        if (! empty($filters['project_id'])) {
            $jeQuery->where('jel.project_id', (int) $filters['project_id']);
        }
        if (! empty($filters['period_from'])) {
            $jeQuery->where('je.journal_date', '>=', $filters['period_from']);
        }
        if (! empty($filters['period_to'])) {
            $jeQuery->where('je.journal_date', '<=', $filters['period_to']);
        }

        $actualRows = $jeQuery
            ->select('jel.account_id', DB::raw('SUM(jel.debit - jel.credit) as actual_amount'))
            ->groupBy('jel.account_id')
            ->get()
            ->keyBy('account_id');

        $allAccountIds = $budgetRows->keys()->merge($actualRows->keys())->unique();

        $rows = $allAccountIds->map(function ($accountId) use ($budgetRows, $actualRows) {
            $budget = $budgetRows->get($accountId);
            $actual = $actualRows->get($accountId);
            $budgetAmount = (float) ($budget->budget_amount ?? 0);
            $actualAmount = (float) ($actual->actual_amount ?? 0);
            $variance = $budgetAmount - $actualAmount;
            $variancePct = $budgetAmount != 0 ? round(($variance / $budgetAmount) * 100, 2) : null;

            return [
                'account_id' => $accountId,
                'account_code' => $budget->account_code ?? null,
                'account_name' => $budget->account_name ?? null,
                'budget_amount' => number_format($budgetAmount, 2, '.', ''),
                'actual_amount' => number_format($actualAmount, 2, '.', ''),
                'variance' => number_format($variance, 2, '.', ''),
                'variance_pct' => $variancePct,
                'over_budget' => $actualAmount > $budgetAmount,
            ];
        })->values()->all();

        $totalBudget = collect($rows)->sum(fn ($r) => (float) $r['budget_amount']);
        $totalActual = collect($rows)->sum(fn ($r) => (float) $r['actual_amount']);

        return [
            'period' => ['budget_period_id' => $period->id, 'name' => $period->name],
            'rows' => $rows,
            'totals' => [
                'budget_amount' => number_format($totalBudget, 2, '.', ''),
                'actual_amount' => number_format($totalActual, 2, '.', ''),
                'variance' => number_format($totalBudget - $totalActual, 2, '.', ''),
            ],
        ];
    }
}
