<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Services\BudgetAnalysisService;
use App\Modules\Budget\Support\BudgetState;
use App\Shared\Exceptions\ApiException;

/**
 * Fase 2 — mesin inti. Satu agregasi melahirkan semua view, jadi yang diuji di
 * sini adalah kebenaran angkanya (tanda, sumber, alokasi) dan konsistensi antar
 * level drill-down.
 */
class BudgetAnalysisTest extends BudgetAnalysisTestCase
{
    public function test_revenue_actual_is_positive_when_earned(): void
    {
        $this->bootScenario();
        $revenue = $this->makeAccount('4000', 'Tuition Revenue', 'revenue');
        $this->budgetLine($revenue, amount: 10_000, departmentId: $this->dept->id);

        // Pendapatan diakui di sisi kredit.
        $this->postJournalLine($revenue->id, '2026-03-10', credit: 7_500, departmentId: $this->dept->id);

        $row = $this->firstRow(['group_by' => ['account'], 'account_id' => $revenue->id]);

        // `SUM(debit − credit)` yang lama menghasilkan −7.500 di sini, sehingga
        // over_budget tidak pernah menyala untuk akun pendapatan (G5).
        $this->assertSame('7500.00', $row['actual_amount']);
        $this->assertSame('revenue', $row['direction']);
        // Pendapatan di bawah target = unfavorable.
        $this->assertSame(BudgetState::OVER_BUDGET, $row['state']);
        $this->assertSame(75.0, $row['utilization_pct']);
    }

    public function test_revenue_above_target_is_favorable(): void
    {
        $this->bootScenario();
        $revenue = $this->makeAccount('4000', 'Tuition Revenue', 'revenue');
        $this->budgetLine($revenue, amount: 10_000, departmentId: $this->dept->id);
        $this->postJournalLine($revenue->id, '2026-03-10', credit: 12_000, departmentId: $this->dept->id);

        $row = $this->firstRow(['group_by' => ['account'], 'account_id' => $revenue->id]);

        $this->assertSame(BudgetState::UNDER_BUDGET, $row['state']);
        // Variance ditulis supaya positif = favorable, jadi kelebihan pendapatan positif.
        $this->assertSame('2000.00', $row['variance']);
    }

    public function test_obsolete_and_unposted_journals_are_not_actual(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 10_000, departmentId: $this->dept->id);

        $this->postJournalLine($this->account->id, '2026-03-10', debit: 1_000, departmentId: $this->dept->id);
        // Jurnal yang sudah digantikan — inilah yang lolos di query lama (G4).
        $this->postJournalLine($this->account->id, '2026-03-11', debit: 500, departmentId: $this->dept->id, overrides: ['is_obsolete' => true]);
        $this->postJournalLine($this->account->id, '2026-03-12', debit: 300, departmentId: $this->dept->id, overrides: ['status' => 'draft']);
        $this->postJournalLine($this->account->id, '2026-03-13', debit: 200, departmentId: $this->dept->id, overrides: ['status' => 'void']);

        $row = $this->firstRow(['group_by' => ['account']]);

        $this->assertSame('1000.00', $row['actual_amount']);
    }

    public function test_actual_outside_the_budget_period_is_excluded(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 10_000, departmentId: $this->dept->id);

        $this->postJournalLine($this->account->id, '2026-03-10', debit: 1_000, departmentId: $this->dept->id);
        $this->postJournalLine($this->account->id, '2027-01-10', debit: 9_000, departmentId: $this->dept->id);

        $this->assertSame('1000.00', $this->firstRow(['group_by' => ['account']])['actual_amount']);
    }

    public function test_drill_down_levels_sum_consistently(): void
    {
        $this->bootScenario();
        $marketing = $this->makeDepartment('MKT', 'Marketing');
        $project = $this->makeProject('PRJ-1', 'Class Meeting');

        $this->budgetLine($this->account, amount: 4_000, departmentId: $this->dept->id);
        $this->budgetLine($this->account, amount: 6_000, departmentId: $marketing->id, projectId: $project->id);

        $this->postJournalLine($this->account->id, '2026-03-10', debit: 1_500, departmentId: $this->dept->id);
        $this->postJournalLine($this->account->id, '2026-04-10', debit: 2_500, departmentId: $marketing->id, projectId: $project->id);

        $total = $this->analyze(['group_by' => []]);
        $byDepartment = $this->analyze(['group_by' => ['department']]);
        $byDepartmentAccount = $this->analyze(['group_by' => ['department', 'account']]);

        $this->assertSame('10000.00', $total['totals']['budget_amount']);
        $this->assertSame('4000.00', $total['totals']['actual_amount']);

        // Setiap level menjumlah ke angka yang sama karena berasal dari agregasi
        // yang sama — bukan dari tiga query yang kebetulan mirip.
        $this->assertSame($total['totals']['budget_amount'], $byDepartment['totals']['budget_amount']);
        $this->assertSame($total['totals']['actual_amount'], $byDepartment['totals']['actual_amount']);
        $this->assertSame($total['totals']['budget_amount'], $byDepartmentAccount['totals']['budget_amount']);
        $this->assertSame($total['totals']['actual_amount'], $byDepartmentAccount['totals']['actual_amount']);

        $this->assertCount(2, $byDepartment['rows']);
    }

    public function test_drill_down_by_parent_row_filter_matches_the_parent_row(): void
    {
        $this->bootScenario();
        $marketing = $this->makeDepartment('MKT', 'Marketing');
        $project = $this->makeProject('PRJ-1', 'Class Meeting');

        $this->budgetLine($this->account, amount: 6_000, departmentId: $marketing->id, projectId: $project->id);
        $this->postJournalLine($this->account->id, '2026-04-10', debit: 2_500, departmentId: $marketing->id, projectId: $project->id);

        $departmentRow = collect($this->analyze(['group_by' => ['department']])['rows'])
            ->firstWhere('department_id', $marketing->id);

        $drilled = $this->analyze(['group_by' => ['project'], 'department_id' => $marketing->id]);

        $this->assertSame($departmentRow['budget_amount'], $drilled['totals']['budget_amount']);
        $this->assertSame($departmentRow['actual_amount'], $drilled['totals']['actual_amount']);
    }

    public function test_state_and_utilization_edge_cases(): void
    {
        $this->bootScenario();
        $unbudgeted = $this->makeAccount('6200', 'Entertainment');

        // Ada anggaran, belum ada realisasi.
        $this->budgetLine($this->account, amount: 5_000, departmentId: $this->dept->id);
        // Ada realisasi, tanpa anggaran.
        $this->postJournalLine($unbudgeted->id, '2026-03-10', debit: 900, departmentId: $this->dept->id);

        $rows = collect($this->analyze(['group_by' => ['account']])['rows'])->keyBy('account_id');

        $this->assertSame(BudgetState::NO_ACTUAL, $rows[$this->account->id]['state']);
        $this->assertSame(0.0, $rows[$this->account->id]['utilization_pct']);

        $this->assertSame(BudgetState::NO_BUDGET, $rows[$unbudgeted->id]['state']);
        // Anggaran 0 → null. Bukan 0 (terbaca "belum terpakai") dan bukan INF.
        $this->assertNull($rows[$unbudgeted->id]['utilization_pct']);
        $this->assertNull($rows[$unbudgeted->id]['variance_pct']);
    }

    public function test_exact_match_is_on_budget(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 5_000, departmentId: $this->dept->id);
        $this->postJournalLine($this->account->id, '2026-03-10', debit: 5_000, departmentId: $this->dept->id);

        $this->assertSame(BudgetState::ON_BUDGET, $this->firstRow(['group_by' => ['account']])['state']);
    }

    public function test_partial_period_is_flagged(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 12_000, departmentId: $this->dept->id);
        $this->postJournalLine($this->account->id, '2026-02-10', debit: 1_000, departmentId: $this->dept->id);
        $this->postJournalLine($this->account->id, '2026-08-10', debit: 5_000, departmentId: $this->dept->id);

        $full = $this->analyze(['group_by' => ['account']]);
        $this->assertFalse($full['meta']['is_partial_period']);

        $partial = $this->analyze(['group_by' => ['account'], 'date_from' => '2026-01-01', 'date_to' => '2026-06-30']);

        // Anggaran tetap setahun penuh sementara actual dipotong — tanpa penanda
        // ini, separuh tahun terbaca seolah sangat hemat.
        $this->assertTrue($partial['meta']['is_partial_period']);
        $this->assertSame('12000.00', $partial['rows'][0]['budget_amount']);
        $this->assertSame('1000.00', $partial['rows'][0]['actual_amount']);
    }

    public function test_annual_rows_are_not_split_into_fake_monthly_numbers(): void
    {
        $this->bootScenario();
        // Baris tahunan.
        $this->budgetLine($this->account, amount: 12_000, departmentId: $this->dept->id);
        // Baris bulanan untuk akun lain.
        $march = $this->makeAccount('6300', 'March Only');
        $this->budgetLine($march, amount: 800, departmentId: $this->dept->id, periodMonth: '2026-03');

        $rows = collect($this->analyze(['group_by' => ['period', 'account'], 'allocation' => 'annual_row'])['rows']);

        $annualRow = $rows->firstWhere('account_id', $this->account->id);
        $this->assertNull($annualRow['period_month'], 'Baris tahunan harus tetap di bucket tahunan.');
        $this->assertSame('12000.00', $annualRow['budget_amount']);

        $marchRow = $rows->firstWhere('account_id', $march->id);
        $this->assertSame('2026-03', $marchRow['period_month']);
    }

    public function test_even_allocation_spreads_annual_rows_across_months(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 12_000, departmentId: $this->dept->id);

        $rows = collect($this->analyze(['group_by' => ['period'], 'allocation' => 'even'])['rows']);

        $this->assertCount(12, $rows);
        $this->assertSame('1000.00', $rows->firstWhere('period_month', '2026-03')['budget_amount']);
    }

    public function test_monthly_grouping_matches_actual_month_by_month(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 1_000, departmentId: $this->dept->id, periodMonth: '2026-03');
        $this->budgetLine($this->account, amount: 2_000, departmentId: $this->dept->id, periodMonth: '2026-04');

        $this->postJournalLine($this->account->id, '2026-03-05', debit: 400, departmentId: $this->dept->id);
        $this->postJournalLine($this->account->id, '2026-04-05', debit: 2_500, departmentId: $this->dept->id);

        $rows = collect($this->analyze(['group_by' => ['period']])['rows'])->keyBy('period_month');

        $this->assertSame('400.00', $rows['2026-03']['actual_amount']);
        $this->assertSame(BudgetState::UNDER_BUDGET, $rows['2026-03']['state']);

        $this->assertSame('2500.00', $rows['2026-04']['actual_amount']);
        $this->assertSame(BudgetState::OVER_BUDGET, $rows['2026-04']['state']);
    }

    public function test_mixed_direction_rows_are_labelled_mixed(): void
    {
        $this->bootScenario();
        $revenue = $this->makeAccount('4000', 'Tuition Revenue', 'revenue');

        $this->budgetLine($this->account, amount: 4_000, departmentId: $this->dept->id);
        $this->budgetLine($revenue, amount: 10_000, departmentId: $this->dept->id);

        $row = $this->firstRow(['group_by' => ['department']]);

        // Satu baris yang mencampur pendapatan dan beban tidak punya makna
        // "favorable" tunggal, jadi arahnya ditandai apa adanya.
        $this->assertSame(BudgetAnalysisService::DIRECTION_MIXED, $row['direction']);

        // Difilter satu arah, ambiguitasnya hilang.
        $expenseOnly = $this->firstRow(['group_by' => ['department'], 'direction' => 'expense']);
        $this->assertSame('expense', $expenseOnly['direction']);
        $this->assertSame('4000.00', $expenseOnly['budget_amount']);
    }

    public function test_unknown_group_by_is_rejected_before_touching_sql(): void
    {
        $this->bootScenario();

        try {
            $this->analyze(['group_by' => ['department; drop table budget_lines']]);
            $this->fail('Dimensi di luar allowlist seharusnya ditolak.');
        } catch (ApiException $e) {
            $this->assertSame('BUDGET_INVALID_GROUP_BY', $e->codeName);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_only_the_active_version_is_used_by_default(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 5_000, departmentId: $this->dept->id);

        $this->assertSame('5000.00', $this->analyze(['group_by' => ['account']])['totals']['budget_amount']);

        $this->submission->update(['is_active' => false, 'status' => 'superseded']);

        $this->assertSame('0.00', $this->analyze(['group_by' => ['account']])['totals']['budget_amount']);
        // `version=all` tetap bisa melihat versi lama.
        $this->assertSame('5000.00', $this->analyze(['group_by' => ['account'], 'version' => 'all'])['totals']['budget_amount']);
    }
}
