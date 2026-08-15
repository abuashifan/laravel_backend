<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\BudgetSubmission;
use App\Modules\Budget\Services\BudgetMatchResolver;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Project;

/**
 * Fase 2 — tangga spesifisitas. Satu test per prioritas, plus pembuktian bahwa
 * prioritas yang lebih spesifik menang, plus dua cacat lama (G7, G8).
 */
class BudgetMatchResolverTest extends BudgetTestCase
{
    private ChartOfAccount $account;

    private Department $dept;

    private Project $project;

    private BudgetSubmission $submission;

    /**
     * @return array{resolver:BudgetMatchResolver}
     */
    private function scenario(): array
    {
        $ctx = $this->setUpTenant();

        $this->account = $ctx['account'];
        $this->dept = $ctx['dept'];
        $this->project = $this->makeProject('PRJ-1', 'Class Meeting');

        $period = BudgetPeriod::query()->create([
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
            'budget_period_id' => $period->id,
            'department_id' => $this->dept->id,
            'status' => 'approved',
            'is_active' => true,
            'version_no' => 1,
            'revision_number' => 1,
            'created_by' => $ctx['user']->id,
        ]);

        return ['resolver' => app(BudgetMatchResolver::class)];
    }

    private function line(?int $departmentId, ?int $projectId, ?string $month, float $amount): BudgetLine
    {
        return BudgetLine::query()->create([
            'budget_submission_id' => $this->submission->id,
            'account_id' => $this->account->id,
            'department_id' => $departmentId,
            'project_id' => $projectId,
            'period_month' => $month,
            'direction' => 'expense',
            'amount' => $amount,
        ]);
    }

    public function test_priority_1_exact_match_on_all_dimensions(): void
    {
        ['resolver' => $resolver] = $this->scenario();
        $expected = $this->line($this->dept->id, $this->project->id, '2026-03', 100);

        $this->assertSame(
            $expected->id,
            $resolver->resolve($this->account->id, $this->dept->id, $this->project->id, '2026-03')?->id,
        );
    }

    public function test_priority_2_department_and_project_annual(): void
    {
        ['resolver' => $resolver] = $this->scenario();
        $expected = $this->line($this->dept->id, $this->project->id, null, 200);

        $this->assertSame(
            $expected->id,
            $resolver->resolve($this->account->id, $this->dept->id, $this->project->id, '2026-03')?->id,
        );
    }

    public function test_priority_3_department_without_project_for_the_month(): void
    {
        ['resolver' => $resolver] = $this->scenario();
        $expected = $this->line($this->dept->id, null, '2026-03', 300);

        $this->assertSame(
            $expected->id,
            $resolver->resolve($this->account->id, $this->dept->id, $this->project->id, '2026-03')?->id,
        );
    }

    public function test_priority_4_department_annual(): void
    {
        ['resolver' => $resolver] = $this->scenario();
        $expected = $this->line($this->dept->id, null, null, 400);

        $this->assertSame(
            $expected->id,
            $resolver->resolve($this->account->id, $this->dept->id, $this->project->id, '2026-03')?->id,
        );
    }

    public function test_priority_5_company_level_project_budget(): void
    {
        ['resolver' => $resolver] = $this->scenario();
        $expected = $this->line(null, $this->project->id, '2026-03', 500);

        $this->assertSame(
            $expected->id,
            $resolver->resolve($this->account->id, $this->dept->id, $this->project->id, '2026-03')?->id,
        );
    }

    public function test_priority_6_company_level_annual_budget(): void
    {
        ['resolver' => $resolver] = $this->scenario();
        $expected = $this->line(null, null, null, 600);

        $this->assertSame(
            $expected->id,
            $resolver->resolve($this->account->id, $this->dept->id, $this->project->id, '2026-03')?->id,
        );
    }

    public function test_more_specific_line_wins_over_less_specific_one(): void
    {
        ['resolver' => $resolver] = $this->scenario();

        $exact = $this->line($this->dept->id, $this->project->id, '2026-03', 100);
        $this->line($this->dept->id, $this->project->id, null, 200);
        $this->line($this->dept->id, null, null, 400);
        $this->line(null, null, null, 600);

        $this->assertSame(
            $exact->id,
            $resolver->resolve($this->account->id, $this->dept->id, $this->project->id, '2026-03')?->id,
        );
    }

    /**
     * G7 — dulu baris jurnal ber-project_id hanya dicocokkan ke anggaran berproyek
     * sama, sehingga belanja proyek lolos sepenuhnya dari anggaran departemen.
     */
    public function test_project_spending_falls_back_to_the_department_budget(): void
    {
        ['resolver' => $resolver] = $this->scenario();
        $departmentBudget = $this->line($this->dept->id, null, null, 400);

        $matched = $resolver->resolve($this->account->id, $this->dept->id, $this->project->id, '2026-03');

        $this->assertSame($departmentBudget->id, $matched?->id);
    }

    /**
     * G8 — dulu `when($departmentId, …)` tidak memasang filter saat null, lalu
     * `first()` mengambil baris sembarang; baris jurnal tanpa departemen bisa
     * mencocok anggaran departemen mana pun.
     */
    public function test_journal_line_without_department_does_not_match_a_department_budget(): void
    {
        ['resolver' => $resolver] = $this->scenario();
        $this->line($this->dept->id, null, null, 400);

        $this->assertNull($resolver->resolve($this->account->id, null, null, '2026-03'));
    }

    public function test_only_active_approved_versions_are_matched(): void
    {
        ['resolver' => $resolver] = $this->scenario();
        $this->line($this->dept->id, null, null, 400);

        $this->submission->update(['is_active' => false]);
        $this->assertNull($resolver->resolve($this->account->id, $this->dept->id, null, '2026-03'));

        $this->submission->update(['is_active' => true, 'status' => 'draft']);
        $this->assertNull($resolver->resolve($this->account->id, $this->dept->id, null, '2026-03'));
    }

    public function test_month_outside_the_budget_period_matches_nothing(): void
    {
        ['resolver' => $resolver] = $this->scenario();
        $this->line($this->dept->id, null, null, 400);

        $this->assertNull($resolver->resolve($this->account->id, $this->dept->id, null, '2027-03'));
    }
}
