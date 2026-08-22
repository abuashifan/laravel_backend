<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Models\BudgetSubmission;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Fase 5 — revisi yang menjaga riwayat (G2) dan audit trail (G12).
 */
class BudgetVersioningTest extends BudgetTestCase
{
    /**
     * @return array{ctx:array<string,mixed>,submission:array<string,mixed>}
     */
    private function approvedSubmission(float $amount = 15_000_000): array
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
        ]);

        $period = $this->postJson('/api/budget-periods', [
            'name' => 'Anggaran 2026',
            'fiscal_year' => 2026,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [['account_id' => $ctx['account']->id, 'amount' => $amount]],
        ], $ctx['headers'])->assertStatus(200);

        $submission = $this->postJson("/api/budget-submissions/{$submission['id']}/submit", [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved')
            ->json('data');

        return ['ctx' => $ctx, 'submission' => $submission, 'period' => $period];
    }

    public function test_revising_creates_a_new_version_and_supersedes_the_old_one(): void
    {
        ['ctx' => $ctx, 'submission' => $v1] = $this->approvedSubmission();

        $v2 = $this->postJson("/api/budget-submissions/{$v1['id']}/revise", [
            'revision_reason' => 'Kenaikan UMR membuat pagu gaji lama tidak cukup.',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->assertSame(2, $v2['version_no']);
        $this->assertSame('draft', $v2['status']);
        $this->assertSame($v1['id'], $v2['parent_submission_id']);
        $this->assertFalse((bool) $v2['is_active']);

        $old = BudgetSubmission::query()->findOrFail($v1['id']);
        $this->assertSame('superseded', $old->status);
        $this->assertFalse((bool) $old->is_active);
    }

    public function test_old_version_lines_survive_the_revision_untouched(): void
    {
        ['ctx' => $ctx, 'submission' => $v1] = $this->approvedSubmission(15_000_000);

        $this->postJson("/api/budget-submissions/{$v1['id']}/revise", [
            'revision_reason' => 'Kenaikan UMR membuat pagu gaji lama tidak cukup.',
        ], $ctx['headers'])->assertStatus(201);

        $oldLines = BudgetLine::query()->where('budget_submission_id', $v1['id'])->get();

        // Riwayat terjaga karena baris lama tidak pernah ditimpa.
        $this->assertCount(1, $oldLines);
        $this->assertSame('15000000.00', $oldLines[0]->amount);
    }

    public function test_new_version_inherits_the_lines_and_becomes_active_once_approved(): void
    {
        ['ctx' => $ctx, 'submission' => $v1] = $this->approvedSubmission(15_000_000);

        $v2 = $this->postJson("/api/budget-submissions/{$v1['id']}/revise", [
            'revision_reason' => 'Kenaikan UMR membuat pagu gaji lama tidak cukup.',
        ], $ctx['headers'])->json('data');

        // Barisnya ikut tersalin, jadi revisi tidak dimulai dari nol.
        $this->assertCount(1, $v2['lines']);

        $this->putJson("/api/budget-submissions/{$v2['id']}/lines", [
            'lines' => [['account_id' => $ctx['account']->id, 'amount' => 18_000_000]],
        ], $ctx['headers'])->assertStatus(200);

        $this->postJson("/api/budget-submissions/{$v2['id']}/submit", [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $active = BudgetSubmission::query()->where('is_active', true)->get();

        // Tepat satu versi aktif per (periode, departemen), dalam keadaan apa pun.
        $this->assertCount(1, $active);
        $this->assertSame($v2['id'], $active[0]->id);
    }

    public function test_three_versions_are_all_readable_with_only_the_last_active(): void
    {
        ['ctx' => $ctx, 'submission' => $v1] = $this->approvedSubmission(15_000_000);

        $v2 = $this->reviseAndApprove($ctx, $v1['id'], 18_000_000);
        $v3 = $this->reviseAndApprove($ctx, $v2['id'], 20_000_000);

        $versions = $this->getJson("/api/budget-submissions/{$v3['id']}/versions", $ctx['headers'])
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(3, $versions);
        $this->assertSame([1, 2, 3], array_column($versions, 'version_no'));
        $this->assertSame(['15000000.00', '18000000.00', '20000000.00'], array_column($versions, 'total_amount'));
        $this->assertSame([false, false, true], array_column($versions, 'is_active'));
    }

    public function test_revise_is_rejected_for_a_draft_submission(): void
    {
        $ctx = $this->setUpTenant();

        $period = $this->postJson('/api/budget-periods', [
            'name' => 'Anggaran 2026', 'fiscal_year' => 2026,
            'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
        ], $ctx['headers'])->json('data');

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        // Draft cukup diedit langsung — revisi hanya untuk yang sudah approved.
        $this->postJson("/api/budget-submissions/{$submission['id']}/revise", [
            'revision_reason' => 'Alasan yang cukup panjang untuk lolos validasi.',
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'BUDGET_ALREADY_APPROVED');
    }

    public function test_revise_requires_a_meaningful_reason(): void
    {
        ['ctx' => $ctx, 'submission' => $v1] = $this->approvedSubmission();

        $this->postJson("/api/budget-submissions/{$v1['id']}/revise", [], $ctx['headers'])
            ->assertStatus(422);

        $this->postJson("/api/budget-submissions/{$v1['id']}/revise", [
            'revision_reason' => 'revisi',
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_revise_requires_the_revise_permission(): void
    {
        ['ctx' => $ctx, 'submission' => $v1] = $this->approvedSubmission();

        config(['permissions.roles.budget_viewer' => ['budgets.view']]);

        $viewer = User::factory()->create(['status' => 'active']);
        CompanyUser::query()->create([
            'company_id' => $ctx['company']->id,
            'user_id' => $viewer->id,
            'role' => 'budget_viewer',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        Sanctum::actingAs($viewer, ['*']);

        $this->postJson("/api/budget-submissions/{$v1['id']}/revise", [
            'revision_reason' => 'Alasan yang cukup panjang untuk lolos validasi.',
        ], $ctx['headers'])->assertStatus(403);
    }

    public function test_approved_submission_still_cannot_be_edited_directly(): void
    {
        ['ctx' => $ctx, 'submission' => $v1] = $this->approvedSubmission();

        // Immutability tidak dilonggarkan oleh adanya jalur revise.
        $this->putJson("/api/budget-submissions/{$v1['id']}/lines", [
            'lines' => [['account_id' => $ctx['account']->id, 'amount' => 999]],
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_reject_still_behaves_exactly_as_before(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $period = $this->postJson('/api/budget-periods', [
            'name' => 'Anggaran 2026', 'fiscal_year' => 2026,
            'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
        ], $ctx['headers'])->json('data');

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [['account_id' => $ctx['account']->id, 'amount' => 5_000_000]],
        ], $ctx['headers'])->assertStatus(200);

        $this->postJson("/api/budget-submissions/{$submission['id']}/submit", [], $ctx['headers']);

        // Reject = koreksi SEBELUM disetujui: baris yang sama, revision_number
        // naik, version_no tidak berubah.
        $rejected = $this->postJson("/api/budget-submissions/{$submission['id']}/reject", [
            'rejection_note' => 'Terlalu tinggi',
        ], $ctx['headers'])->assertStatus(200)->json('data');

        $this->assertSame('draft', $rejected['status']);
        $this->assertSame(2, $rejected['revision_number']);
        $this->assertSame(1, $rejected['version_no']);
        $this->assertSame(1, BudgetSubmission::query()->count());
    }

    public function test_every_write_leaves_an_audit_entry(): void
    {
        ['ctx' => $ctx, 'submission' => $v1] = $this->approvedSubmission();

        $this->postJson("/api/budget-submissions/{$v1['id']}/revise", [
            'revision_reason' => 'Kenaikan UMR membuat pagu gaji lama tidak cukup.',
        ], $ctx['headers'])->assertStatus(201);

        $events = DB::connection('tenant')->table('tenant_audit_logs')->pluck('event')->all();

        foreach ([
            'budget.period.created',
            'budget.submission.created',
            'budget.submission.lines_updated',
            'budget.submission.submitted',
            'budget.submission.approved',
            'budget.submission.revised',
        ] as $expected) {
            $this->assertContains($expected, $events, "Audit log `{$expected}` tidak tertulis.");
        }

        $revised = DB::connection('tenant')->table('tenant_audit_logs')
            ->where('event', 'budget.submission.revised')
            ->value('metadata');

        $this->assertStringContainsString('version_no', (string) $revised);
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    private function reviseAndApprove(array $ctx, int $submissionId, float $amount): array
    {
        $next = $this->postJson("/api/budget-submissions/{$submissionId}/revise", [
            'revision_reason' => 'Penyesuaian anggaran menjadi '.number_format($amount).'.',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->putJson("/api/budget-submissions/{$next['id']}/lines", [
            'lines' => [['account_id' => $ctx['account']->id, 'amount' => $amount]],
        ], $ctx['headers'])->assertStatus(200);

        $this->postJson("/api/budget-submissions/{$next['id']}/submit", [], $ctx['headers'])->assertStatus(200);

        return $next;
    }
}
