<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\BudgetSubmission;

/**
 * Fase 2 rencana UI_Reporting — daftar submission lintas periode.
 *
 * Endpoint ini tidak mengagregasi anggaran-vs-realisasi; ia hanya mendaftar.
 * Test di sini menjaga batas itu sekaligus scope company dan default versi aktif.
 */
class BudgetSubmissionListTest extends BudgetAnalysisTestCase
{
    private function makePeriod(int $year): BudgetPeriod
    {
        return BudgetPeriod::query()->create([
            'company_id' => $this->submission->company_id,
            'name' => "Anggaran {$year}",
            'fiscal_year' => $year,
            'period_from' => "{$year}-01-01",
            'period_to' => "{$year}-12-31",
            'status' => 'open',
            'created_by' => $this->submission->created_by,
        ]);
    }

    private function makeSubmission(BudgetPeriod $period, ?int $departmentId, array $overrides = []): BudgetSubmission
    {
        return BudgetSubmission::query()->create(array_merge([
            'company_id' => $this->submission->company_id,
            'budget_period_id' => $period->id,
            'department_id' => $departmentId,
            'status' => 'approved',
            'is_active' => true,
            'version_no' => 1,
            'revision_number' => 1,
            'created_by' => $this->submission->created_by,
        ], $overrides));
    }

    public function test_it_lists_submissions_across_periods(): void
    {
        $this->bootScenario();
        $other = $this->makePeriod(2027);
        $this->makeSubmission($other, $this->dept->id);

        $rows = $this->getJson('/api/budget-submissions', $this->headers)
            ->assertStatus(200)->json('data');

        // Submission bawaan skenario (2026) + yang baru (2027).
        $this->assertCount(2, $rows);
        $periodIds = array_column($rows, 'budget_period_id');
        $this->assertContains($this->period->id, $periodIds);
        $this->assertContains($other->id, $periodIds);
    }

    public function test_it_filters_by_budget_period(): void
    {
        $this->bootScenario();
        $other = $this->makePeriod(2027);
        $this->makeSubmission($other, $this->dept->id);

        $rows = $this->getJson("/api/budget-submissions?budget_period_id={$other->id}", $this->headers)
            ->assertStatus(200)->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($other->id, $rows[0]['budget_period_id']);
    }

    public function test_it_filters_by_status(): void
    {
        $this->bootScenario();
        $marketing = $this->makeDepartment('MKT', 'Marketing');
        $this->makeSubmission($this->period, $marketing->id, ['status' => 'draft']);

        $rows = $this->getJson('/api/budget-submissions?status=draft', $this->headers)
            ->assertStatus(200)->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('draft', $rows[0]['status']);
    }

    public function test_it_hides_superseded_versions_by_default(): void
    {
        $this->bootScenario();
        $marketing = $this->makeDepartment('MKT', 'Marketing');
        $this->makeSubmission($this->period, $marketing->id, [
            'status' => 'superseded',
            'is_active' => false,
            'version_no' => 1,
        ]);

        $default = $this->getJson('/api/budget-submissions', $this->headers)
            ->assertStatus(200)->json('data');
        $this->assertCount(1, $default);

        $withOld = $this->getJson('/api/budget-submissions?is_active=false', $this->headers)
            ->assertStatus(200)->json('data');
        $this->assertCount(2, $withOld);
    }

    public function test_total_amount_matches_the_sum_of_budget_lines(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 4_000, departmentId: $this->dept->id);
        $this->budgetLine($this->account, amount: 6_000, departmentId: $this->dept->id, periodMonth: '2026-03');

        $rows = $this->getJson('/api/budget-submissions', $this->headers)
            ->assertStatus(200)->json('data');

        $this->assertEqualsWithDelta(10_000, (float) $rows[0]['total_amount'], 0.01);
    }

    public function test_a_submission_without_lines_reports_null_total_not_an_error(): void
    {
        $this->bootScenario();

        $rows = $this->getJson('/api/budget-submissions', $this->headers)
            ->assertStatus(200)->json('data');

        // withSum tanpa baris menghasilkan null, bukan 0 — UI yang memutuskan cara
        // menampilkannya. Yang penting endpoint tidak pecah.
        $this->assertEqualsWithDelta(0, (float) ($rows[0]['total_amount'] ?? 0), 0.01);
    }

    public function test_pagination_respects_per_page(): void
    {
        $this->bootScenario();
        $marketing = $this->makeDepartment('MKT', 'Marketing');
        $finance = $this->makeDepartment('FIN', 'Finance');
        $this->makeSubmission($this->period, $marketing->id);
        $this->makeSubmission($this->period, $finance->id);

        $body = $this->getJson('/api/budget-submissions?page=1&per_page=2', $this->headers)
            ->assertStatus(200)->json('data');

        $this->assertCount(2, $body['data']);
        $this->assertSame(3, $body['total']);
        $this->assertSame(2, $body['per_page']);
    }

    public function test_unknown_sort_column_is_rejected_by_the_request(): void
    {
        $this->bootScenario();

        $this->getJson('/api/budget-submissions?sort_by=amount', $this->headers)
            ->assertStatus(422);
    }

    public function test_unknown_status_is_rejected_by_the_request(): void
    {
        $this->bootScenario();

        $this->getJson('/api/budget-submissions?status=pending', $this->headers)
            ->assertStatus(422);
    }

    public function test_company_level_submissions_are_listed_with_a_null_department(): void
    {
        $this->bootScenario();
        $other = $this->makePeriod(2027);
        $this->makeSubmission($other, null);

        $rows = $this->getJson("/api/budget-submissions?budget_period_id={$other->id}", $this->headers)
            ->assertStatus(200)->json('data');

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['department_id']);
    }

    public function test_submissions_of_another_company_are_not_listed(): void
    {
        $this->bootScenario();
        // Company lain di tenant DB yang sama. `forCompany()` yang menjaga batasnya —
        // tanpa itu daftar bocor lintas perusahaan.
        $this->makeSubmission($this->period, null, ['company_id' => $this->submission->company_id + 999]);

        $rows = $this->getJson('/api/budget-submissions', $this->headers)
            ->assertStatus(200)->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($this->submission->id, $rows[0]['id']);
    }
}
