<?php

namespace Tests\Feature\Budget;

use App\Modules\MasterData\Models\Project;

/**
 * Fase 6 — enam belas view sebagai preset di atas satu mesin, Cash Budget,
 * Project Profitability, dan business rules.
 */
class BudgetViewsTest extends BudgetAnalysisTestCase
{
    // ------------------------------------------------------------- endpoint

    public function test_analysis_endpoint_serves_grouped_rows(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 5_000, departmentId: $this->dept->id);
        $this->postJournalLine($this->account->id, '2026-03-10', debit: 2_000, departmentId: $this->dept->id);

        $this->getJson("/api/budget/analysis?budget_period_id={$this->period->id}&group_by[]=account", $this->headers)
            ->assertStatus(200)
            ->assertJsonPath('data.rows.0.budget_amount', '5000.00')
            ->assertJsonPath('data.rows.0.actual_amount', '2000.00')
            ->assertJsonPath('data.rows.0.state', 'under_budget');
    }

    public function test_unknown_group_by_is_rejected_by_the_request(): void
    {
        $this->bootScenario();

        $this->getJson("/api/budget/analysis?budget_period_id={$this->period->id}&group_by[]=customer", $this->headers)
            ->assertStatus(422);
    }

    public function test_preset_report_endpoints_agree_with_the_analysis_endpoint(): void
    {
        $this->bootScenario();
        $marketing = $this->makeDepartment('MKT', 'Marketing');

        $this->budgetLine($this->account, amount: 4_000, departmentId: $this->dept->id);
        $this->budgetLine($this->account, amount: 6_000, departmentId: $marketing->id);
        $this->postJournalLine($this->account->id, '2026-03-10', debit: 1_500, departmentId: $this->dept->id);

        $byAccount = $this->getJson("/api/reports/budget/by-account?budget_period_id={$this->period->id}", $this->headers)
            ->assertStatus(200)->json('data');
        $byCostCenter = $this->getJson("/api/reports/budget/by-cost-center?budget_period_id={$this->period->id}", $this->headers)
            ->assertStatus(200)->json('data');

        // View #1 dan #2 membaca baris yang sama, jadi totalnya wajib identik.
        $this->assertSame('10000.00', $byAccount['totals']['budget_amount']);
        $this->assertSame($byAccount['totals']['budget_amount'], $byCostCenter['totals']['budget_amount']);
        $this->assertSame($byAccount['totals']['actual_amount'], $byCostCenter['totals']['actual_amount']);
        $this->assertCount(2, $byCostCenter['rows']);
    }

    public function test_comparison_alias_still_works(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 5_000, departmentId: $this->dept->id);

        $this->getJson("/api/reports/budget/comparison?budget_period_id={$this->period->id}", $this->headers)
            ->assertStatus(200)
            ->assertJsonPath('data.totals.budget_amount', '5000.00')
            ->assertJsonPath('data.rows.0.over_budget', false);
    }

    public function test_utilization_is_sorted_highest_first(): void
    {
        $this->bootScenario();
        $low = $this->makeAccount('6400', 'Low Usage');

        $this->budgetLine($this->account, amount: 1_000, departmentId: $this->dept->id);
        $this->budgetLine($low, amount: 1_000, departmentId: $this->dept->id);
        $this->postJournalLine($this->account->id, '2026-03-10', debit: 900, departmentId: $this->dept->id);
        $this->postJournalLine($low->id, '2026-03-10', debit: 100, departmentId: $this->dept->id);

        $rows = $this->getJson("/api/reports/budget/utilization?budget_period_id={$this->period->id}", $this->headers)
            ->assertStatus(200)->json('data.rows');

        $this->assertSame($this->account->id, $rows[0]['account_id']);
        $this->assertEqualsWithDelta(90.0, $rows[0]['utilization_pct'], 0.01);
    }

    // ----------------------------------------------------------- cash budget

    public function test_cash_budget_balances_beginning_plus_inflow_minus_outflow(): void
    {
        $this->bootScenario();
        $revenue = $this->makeAccount('4000', 'Tuition Revenue', 'revenue');

        $this->budgetLine($revenue, amount: 10_000, departmentId: $this->dept->id);
        $this->budgetLine($this->account, amount: 4_000, departmentId: $this->dept->id);

        $cash = $this->getJson("/api/budget/cash?budget_period_id={$this->period->id}", $this->headers)
            ->assertStatus(200)->json('data');

        $this->assertSame('10000.00', $cash['budgeted']['inflow']);
        $this->assertSame('4000.00', $cash['budgeted']['outflow']);
        $this->assertSame('6000.00', $cash['budgeted']['net']);
        $this->assertSame(
            number_format((float) $cash['beginning_cash'] + 6_000, 2, '.', ''),
            $cash['budgeted']['ending_cash'],
        );

        // Asumsi akrual wajib ikut dikirim supaya UI bisa menampilkannya.
        $this->assertArrayHasKey('assumption', $cash['meta']);
    }

    public function test_cash_budget_uses_the_beginning_cash_override_when_set(): void
    {
        $this->bootScenario();
        $this->period->update(['beginning_cash_override' => 2_500]);

        $cash = $this->getJson("/api/budget/cash?budget_period_id={$this->period->id}", $this->headers)
            ->assertStatus(200)->json('data');

        $this->assertSame('2500.00', $cash['beginning_cash']);
        $this->assertSame('override', $cash['beginning_cash_source']);
    }

    // ---------------------------------------------------------- project view

    public function test_project_profitability_computes_profit_and_margin(): void
    {
        $this->bootScenario();
        $project = $this->makeProject('PRJ-1', 'Renovasi Kantor');
        $revenue = $this->makeAccount('4000', 'Pendapatan Jasa', 'revenue');
        $material = $this->makeAccount('5000', 'Material', 'expense');

        $this->budgetLine($revenue, amount: 500_000_000, departmentId: $this->dept->id, projectId: $project->id);
        $this->budgetLine($material, amount: 470_000_000, departmentId: $this->dept->id, projectId: $project->id);

        $summary = $this->getJson(
            "/api/budget/projects/{$project->id}/summary?budget_period_id={$this->period->id}",
            $this->headers,
        )->assertStatus(200)->json('data');

        $this->assertSame('500000000.00', $summary['budget']['revenue']);
        $this->assertSame('470000000.00', $summary['budget']['cost']);
        $this->assertSame('30000000.00', $summary['budget']['profit']);
        $this->assertEqualsWithDelta(6.0, $summary['budget']['margin_pct'], 0.01);

        // Keterbatasan invoice lama wajib ikut, bukan hanya ditulis di dokumen.
        $this->assertArrayHasKey('limitation', $summary['meta']);
    }

    public function test_project_without_revenue_returns_null_margin_not_a_division_by_zero(): void
    {
        $this->bootScenario();
        $project = $this->makeProject('PRJ-1', 'Renovasi Kantor');
        $material = $this->makeAccount('5000', 'Material', 'expense');

        $this->budgetLine($material, amount: 1_000, departmentId: $this->dept->id, projectId: $project->id);

        $summary = $this->getJson(
            "/api/budget/projects/{$project->id}/summary?budget_period_id={$this->period->id}",
            $this->headers,
        )->assertStatus(200)->json('data');

        $this->assertNull($summary['budget']['margin_pct']);
        // Hanya beban → profit negatif, bukan error.
        $this->assertSame('-1000.00', $summary['budget']['profit']);
    }

    public function test_project_without_transactions_reports_zero_actual(): void
    {
        $this->bootScenario();
        $project = $this->makeProject('PRJ-1', 'Renovasi Kantor');
        $this->budgetLine($this->account, amount: 1_000, departmentId: $this->dept->id, projectId: $project->id);

        $summary = $this->getJson(
            "/api/budget/projects/{$project->id}/summary?budget_period_id={$this->period->id}",
            $this->headers,
        )->assertStatus(200)->json('data');

        $this->assertSame('0.00', $summary['actual']['cost']);
        $this->assertEqualsWithDelta(0.0, $summary['cost_utilization_pct'], 0.01);
    }

    // -------------------------------------------------------- business rules

    public function test_overlapping_open_budget_periods_are_rejected(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/budget-periods', [
            'name' => 'Semester 1', 'fiscal_year' => 2026,
            'period_from' => '2026-01-01', 'period_to' => '2026-06-30',
        ], $ctx['headers'])->assertStatus(201);

        // Beririsan di bulan Juni.
        $this->postJson('/api/budget-periods', [
            'name' => 'Semester 2', 'fiscal_year' => 2026,
            'period_from' => '2026-06-01', 'period_to' => '2026-12-31',
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'BUDGET_PERIOD_OVERLAP');

        // Tidak beririsan → diterima.
        $this->postJson('/api/budget-periods', [
            'name' => 'Semester 2', 'fiscal_year' => 2026,
            'period_from' => '2026-07-01', 'period_to' => '2026-12-31',
        ], $ctx['headers'])->assertStatus(201);
    }

    public function test_negative_budget_amount_is_rejected_but_zero_is_allowed(): void
    {
        $ctx = $this->setUpTenant();

        $period = $this->postJson('/api/budget-periods', [
            'name' => 'Anggaran 2026', 'fiscal_year' => 2026,
            'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
        ], $ctx['headers'])->json('data');

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        // Nominal negatif ditolak oleh validasi request (min:0).
        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [['account_id' => $ctx['account']->id, 'amount' => -100]],
        ], $ctx['headers'])->assertStatus(422);

        // Anggaran 0 SAH — ia menandai "tidak boleh belanja".
        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [['account_id' => $ctx['account']->id, 'amount' => 0]],
        ], $ctx['headers'])->assertStatus(200);
    }

    public function test_inactive_project_cannot_receive_a_new_budget(): void
    {
        $ctx = $this->setUpTenant();
        $project = $this->makeProject('PRJ-1', 'Proyek Selesai');
        Project::query()->whereKey($project->id)->update(['status' => 'completed']);

        $period = $this->postJson('/api/budget-periods', [
            'name' => 'Anggaran 2026', 'fiscal_year' => 2026,
            'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
        ], $ctx['headers'])->json('data');

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [['account_id' => $ctx['account']->id, 'project_id' => $project->id, 'amount' => 1_000]],
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'BUDGET_PROJECT_NOT_ACTIVE');
    }

    public function test_budget_permissions_include_the_two_new_keys(): void
    {
        $permissions = (array) config('permissions.permissions');

        $this->assertContains('budgets.revise', $permissions);
        $this->assertContains('budgets.export', $permissions);

        // budgets.view sengaja tidak di-gate paket supaya akses baca bertahan
        // saat paket diturunkan.
        $this->assertNotContains('budgets.view', (array) config('plan_features.features.budgeting'));
    }
}
