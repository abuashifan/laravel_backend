<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\BudgetSubmission;
use App\Modules\Budget\Services\BudgetAnalysisService;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;

/**
 * Basis skenario bersama: satu periode 2026, satu submission approved yang
 * aktif, dan helper untuk menambah baris anggaran.
 */
abstract class BudgetAnalysisTestCase extends BudgetTestCase
{
    protected ChartOfAccount $account;

    protected Department $dept;

    protected BudgetPeriod $period;

    protected BudgetSubmission $submission;

    /** @var array<string,string> */
    protected array $headers = [];

    /**
     * @param  array<string,mixed>  $accountingSettingOverrides
     */
    protected function bootScenario(array $accountingSettingOverrides = []): void
    {
        $ctx = $this->setUpTenant(accountingSettingOverrides: $accountingSettingOverrides);
        $this->account = $ctx['account'];
        $this->dept = $ctx['dept'];
        $this->headers = $ctx['headers'];

        $this->period = BudgetPeriod::query()->create([
            'company_id' => $ctx['company']->id,
            'name' => 'Anggaran 2026',
            'fiscal_year' => 2026,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'open',
            'created_by' => $ctx['user']->id,
        ]);

        $this->submission = BudgetSubmission::query()->create([
            'company_id' => $ctx['company']->id,
            'budget_period_id' => $this->period->id,
            'department_id' => $this->dept->id,
            'status' => 'approved',
            'is_active' => true,
            'version_no' => 1,
            'revision_number' => 1,
            'created_by' => $ctx['user']->id,
        ]);
    }

    protected function budgetLine(
        ChartOfAccount $account,
        float $amount,
        ?int $departmentId = null,
        ?int $projectId = null,
        ?string $periodMonth = null,
    ): BudgetLine {
        return BudgetLine::query()->create([
            'budget_submission_id' => $this->submission->id,
            'account_id' => $account->id,
            'department_id' => $departmentId,
            'project_id' => $projectId,
            'period_month' => $periodMonth,
            'direction' => $account->account_type === 'revenue' ? 'revenue' : 'expense',
            'amount' => $amount,
        ]);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    protected function analyze(array $params): array
    {
        return app(BudgetAnalysisService::class)->analyze(
            ['budget_period_id' => $this->period->id] + $params,
        );
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    protected function firstRow(array $params): array
    {
        $rows = $this->analyze($params)['rows'];
        $this->assertNotEmpty($rows, 'Analisis tidak menghasilkan baris apa pun.');

        return $rows[0];
    }
}
