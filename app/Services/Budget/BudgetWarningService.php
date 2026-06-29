<?php

namespace App\Services\Budget;

use App\Models\Tenant\BudgetLine;
use Illuminate\Support\Facades\DB;

class BudgetWarningService
{
    /**
     * Check if posting a transaction would exceed the approved budget.
     * Returns a warning array or null if within budget.
     *
     * @return array{account_id:int,account_name:string,budget_amount:float,actual_amount:float,new_total:float}|null
     */
    public function check(int $companyId, int $accountId, ?int $departmentId, ?int $projectId, string $period, float $amountToPost): ?array
    {
        // Find matching approved budget line (exact match or annual)
        $budgetLine = BudgetLine::query()
            ->whereHas('submission', function ($q) use ($companyId, $departmentId) {
                $q->where('company_id', $companyId)
                    ->where('status', 'approved')
                    ->when($departmentId, fn ($q2) => $q2->where('department_id', $departmentId));
            })
            ->where('account_id', $accountId)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId), fn ($q) => $q->whereNull('project_id'))
            ->where(function ($q) use ($period) {
                $q->where('period', $period)->orWhereNull('period');
            })
            ->orderByRaw('CASE WHEN period IS NOT NULL THEN 0 ELSE 1 END')
            ->first();

        if (! $budgetLine) {
            return null;
        }

        // Sum actual JE lines already posted for this combination
        $actual = (float) DB::connection('tenant')
            ->table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jel.account_id', $accountId)
            ->when($departmentId, fn ($q) => $q->where('jel.department_id', $departmentId))
            ->when($projectId, fn ($q) => $q->where('jel.project_id', $projectId))
            ->whereRaw("strftime('%Y-%m', je.journal_date) = ?", [$period])
            ->selectRaw('COALESCE(SUM(jel.debit - jel.credit), 0) as total')
            ->value('total');

        $budgetAmount = (float) $budgetLine->amount;
        $newTotal = $actual + $amountToPost;

        if ($newTotal <= $budgetAmount) {
            return null;
        }

        return [
            'account_id' => $accountId,
            'budget_amount' => $budgetAmount,
            'actual_amount' => $actual,
            'new_total' => $newTotal,
            'overage' => $newTotal - $budgetAmount,
        ];
    }
}
