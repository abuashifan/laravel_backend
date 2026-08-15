<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Services\BudgetWarningService;
use App\Modules\Budget\Support\BudgetState;

/**
 * Peringatan over-budget saat posting jurnal. Jalur ini dipanggil
 * `JournalEntryController` dan memakai resolver + agregasi yang sama dengan
 * laporan perbandingan — itulah inti fase 2 ("satu logika, dua pemakai").
 */
class BudgetWarningTest extends BudgetAnalysisTestCase
{
    private function warn(?int $departmentId, ?int $projectId, string $month, float $amount): ?array
    {
        return app(BudgetWarningService::class)->check(
            companyId: $this->period->company_id,
            accountId: $this->account->id,
            departmentId: $departmentId,
            projectId: $projectId,
            period: $month,
            amountToPost: $amount,
        );
    }

    public function test_no_warning_when_there_is_no_budget_line(): void
    {
        $this->bootScenario();

        $this->assertNull($this->warn($this->dept->id, null, '2026-03', 999_999));
    }

    public function test_warning_fires_when_posting_would_exceed_a_monthly_budget(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 1_000, departmentId: $this->dept->id, periodMonth: '2026-03');
        $this->postJournalLine($this->account->id, '2026-03-05', debit: 600, departmentId: $this->dept->id);

        $this->assertNull($this->warn($this->dept->id, null, '2026-03', 300));

        $warning = $this->warn($this->dept->id, null, '2026-03', 500);

        $this->assertNotNull($warning);
        $this->assertSame(1_000.0, $warning['budget_amount']);
        $this->assertSame(600.0, $warning['actual_amount']);
        $this->assertSame(1_100.0, $warning['new_total']);
        $this->assertSame(100.0, $warning['overage']);
    }

    /**
     * G6 — anggaran tahunan dulu dibandingkan dengan actual SATU bulan, sehingga
     * anggaran 12.000 setahun baru menyala kalau satu bulan saja tembus 12.000.
     */
    public function test_annual_budget_is_compared_against_cumulative_actual(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 12_000, departmentId: $this->dept->id);

        foreach (['01', '02', '03', '04', '05'] as $month) {
            $this->postJournalLine($this->account->id, "2026-{$month}-10", debit: 2_300, departmentId: $this->dept->id);
        }

        // Akumulasi Jan–Mei = 11.500. Tambahan 800 melewati pagu setahun,
        // padahal satu bulan pun tidak mendekati 12.000.
        $warning = $this->warn($this->dept->id, null, '2026-05', 800);

        $this->assertNotNull($warning);
        $this->assertSame(11_500.0, $warning['actual_amount']);
        $this->assertSame(300.0, $warning['overage']);
    }

    /**
     * G7 — belanja berproyek harus ikut membebani anggaran departemen saat tidak
     * ada anggaran khusus proyek, dan actual-nya dihitung lintas proyek.
     */
    public function test_project_spending_consumes_the_department_budget(): void
    {
        $this->bootScenario();
        $project = $this->makeProject('PRJ-1', 'Class Meeting');
        $other = $this->makeProject('PRJ-2', 'Study Tour');

        $this->budgetLine($this->account, amount: 1_000, departmentId: $this->dept->id, periodMonth: '2026-03');

        $this->postJournalLine($this->account->id, '2026-03-05', debit: 400, departmentId: $this->dept->id, projectId: $project->id);
        $this->postJournalLine($this->account->id, '2026-03-06', debit: 400, departmentId: $this->dept->id, projectId: $other->id);

        // Cakupan actual mengikuti dimensi baris anggaran (proyek NULL = semua
        // proyek), jadi kedua belanja proyek terhitung.
        $warning = $this->warn($this->dept->id, $project->id, '2026-03', 300);

        $this->assertNotNull($warning);
        $this->assertSame(800.0, $warning['actual_amount']);
    }

    /**
     * G8 — baris jurnal tanpa departemen tidak boleh mencocok anggaran
     * departemen mana pun.
     */
    public function test_journal_without_department_does_not_consume_a_department_budget(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 100, departmentId: $this->dept->id, periodMonth: '2026-03');

        $this->assertNull($this->warn(null, null, '2026-03', 999_999));
    }

    public function test_obsolete_journals_do_not_count_towards_the_warning(): void
    {
        $this->bootScenario();
        $this->budgetLine($this->account, amount: 1_000, departmentId: $this->dept->id, periodMonth: '2026-03');

        $this->postJournalLine($this->account->id, '2026-03-05', debit: 900, departmentId: $this->dept->id, overrides: ['is_obsolete' => true]);

        $this->assertNull($this->warn($this->dept->id, null, '2026-03', 900));
    }

    public function test_journal_without_department_matches_a_company_level_budget(): void
    {
        $this->bootScenario();
        // Anggaran tingkat perusahaan: departemen NULL.
        $this->budgetLine($this->account, amount: 1_000, departmentId: null, periodMonth: '2026-03');

        $warning = $this->warn(null, null, '2026-03', 1_500);

        $this->assertNotNull($warning);
        $this->assertSame('department:all|project:all|period:2026-03', $warning['matched_scope']);
    }

    public function test_project_budget_wins_over_the_department_budget(): void
    {
        $this->bootScenario();
        $project = $this->makeProject('PRJ-1', 'Class Meeting');

        $this->budgetLine($this->account, amount: 10_000, departmentId: $this->dept->id, periodMonth: '2026-03');
        $this->budgetLine($this->account, amount: 500, departmentId: $this->dept->id, projectId: $project->id, periodMonth: '2026-03');

        $warning = $this->warn($this->dept->id, $project->id, '2026-03', 600);

        // Yang tersentuh anggaran proyek (500), bukan anggaran departemen (10.000).
        $this->assertNotNull($warning);
        $this->assertSame(500.0, $warning['budget_amount']);
        $this->assertSame("department:{$this->dept->id}|project:{$project->id}|period:2026-03", $warning['matched_scope']);
    }

    /**
     * Melampaui target pendapatan adalah kabar baik. Peringatannya tetap muncul
     * (angkanya informatif) tapi state-nya favorable — UI harus menghijaukannya.
     */
    public function test_revenue_above_target_is_reported_as_favorable(): void
    {
        $this->bootScenario();
        $revenue = $this->makeAccount('4000', 'Tuition Revenue', 'revenue');
        $this->budgetLine($revenue, amount: 1_000, departmentId: $this->dept->id, periodMonth: '2026-03');

        $warning = app(BudgetWarningService::class)->check(
            companyId: $this->period->company_id,
            accountId: $revenue->id,
            departmentId: $this->dept->id,
            projectId: null,
            period: '2026-03',
            amountToPost: 1_400,
        );

        $this->assertNotNull($warning);
        $this->assertSame('revenue', $warning['direction']);
        $this->assertSame(BudgetState::UNDER_BUDGET, $warning['state']);
    }

    /**
     * Peringatan anggaran **tidak pernah** memblokir posting. Memblokir adalah
     * keputusan bisnis tersendiri yang belum diambil — jangan diubah tanpa itu.
     */
    public function test_posting_a_journal_is_never_blocked_by_a_budget_warning(): void
    {
        // Jurnal harus tersimpan sebagai draf dulu supaya endpoint /post yang
        // membawa `meta.warnings` benar-benar dilewati.
        $this->bootScenario([
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);
        $counterAccount = $this->makeAccount('1100', 'Kas', 'asset');
        $this->budgetLine($this->account, amount: 100, departmentId: $this->dept->id, periodMonth: '2026-03');

        $journal = $this->postJson('/api/journals', [
            'journal_date' => '2026-03-10',
            'description' => 'Belanja jauh melebihi anggaran',
            'lines' => [
                ['account_id' => $this->account->id, 'department_id' => $this->dept->id, 'debit' => 5_000, 'credit' => 0],
                ['account_id' => $counterAccount->id, 'debit' => 0, 'credit' => 5_000],
            ],
        ], $this->headers)->assertStatus(201)->json('data');

        $response = $this->postJson("/api/journals/{$journal['id']}/post", [], $this->headers)
            ->assertStatus(200);

        $response->assertJsonPath('data.status', 'posted');

        $warnings = $response->json('meta.warnings');
        $this->assertNotEmpty($warnings, 'Peringatan over-budget seharusnya ikut dikembalikan.');
        $this->assertSame('expense', $warnings[0]['direction']);
        $this->assertSame(BudgetState::OVER_BUDGET, $warnings[0]['state']);
    }
}
