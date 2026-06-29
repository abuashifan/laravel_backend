<?php

namespace Tests\Feature\Budget;

class BudgetFlowTest extends BudgetTestCase
{
    public function test_auto_post_submission_flow_and_consolidation(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
        ]);

        // Create period
        $period = $this->postJson('/api/budget-periods', [
            'name' => 'Anggaran 2026',
            'fiscal_year' => 2026,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        // Create submission
        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        // Update lines
        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [
                ['account_id' => $ctx['account']->id, 'amount' => 10000000],
            ],
        ], $ctx['headers'])->assertStatus(200)
            ->assertJsonPath('data.lines.0.account_name', 'Salaries Expense')
            ->assertJsonPath('data.lines.0.account_code', '6000');

        // Submit → auto post → approved
        $this->postJson("/api/budget-submissions/{$submission['id']}/submit", [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        // Consolidation reflects approved submission (validates coa.account_name column)
        $this->getJson("/api/budget-periods/{$period['id']}/consolidation?by=department", $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.rows.0.department_name', 'Operational')
            ->assertJsonPath('data.rows.0.accounts.0.account_name', 'Salaries Expense')
            ->assertJsonPath('data.grand_total', '10000000.00');

        // Comparison report (validates coa.account_code / account_name columns)
        $this->getJson("/api/reports/budget/comparison?budget_period_id={$period['id']}", $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.rows.0.account_code', '6000')
            ->assertJsonPath('data.totals.budget_amount', '10000000.00');
    }

    public function test_non_auto_post_approval_chain(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $period = $this->postJson('/api/budget-periods', [
            'name' => 'Anggaran 2026',
            'fiscal_year' => 2026,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
        ], $ctx['headers'])->json('data');

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [['account_id' => $ctx['account']->id, 'amount' => 5000000]],
        ], $ctx['headers'])->assertStatus(200);

        // Submit → submitted (no auto-post)
        $this->postJson("/api/budget-submissions/{$submission['id']}/submit", [], $ctx['headers'])
            ->assertStatus(200)->assertJsonPath('data.status', 'submitted');

        // Cannot approve finance before head
        $this->postJson("/api/budget-submissions/{$submission['id']}/approve-finance", [], $ctx['headers'])
            ->assertStatus(422);

        // Head approves
        $this->postJson("/api/budget-submissions/{$submission['id']}/approve-head", [], $ctx['headers'])
            ->assertStatus(200)->assertJsonPath('data.status', 'approved_by_head');

        // Finance approves
        $this->postJson("/api/budget-submissions/{$submission['id']}/approve-finance", [], $ctx['headers'])
            ->assertStatus(200)->assertJsonPath('data.status', 'approved');
    }

    public function test_reject_returns_to_draft_and_increments_revision(): void
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
            'lines' => [['account_id' => $ctx['account']->id, 'amount' => 5000000]],
        ], $ctx['headers'])->assertStatus(200);

        $this->postJson("/api/budget-submissions/{$submission['id']}/submit", [], $ctx['headers']);

        // Reject requires note
        $this->postJson("/api/budget-submissions/{$submission['id']}/reject", [], $ctx['headers'])
            ->assertStatus(422);

        $this->postJson("/api/budget-submissions/{$submission['id']}/reject", [
            'rejection_note' => 'Terlalu tinggi',
        ], $ctx['headers'])->assertStatus(200)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.revision_number', 2);
    }

    public function test_duplicate_line_rejected(): void
    {
        $ctx = $this->setUpTenant();

        $period = $this->postJson('/api/budget-periods', [
            'name' => 'Anggaran 2026', 'fiscal_year' => 2026,
            'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
        ], $ctx['headers'])->json('data');

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [
                ['account_id' => $ctx['account']->id, 'amount' => 1000],
                ['account_id' => $ctx['account']->id, 'amount' => 2000],
            ],
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_cannot_edit_approved_submission(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
        ]);

        $period = $this->postJson('/api/budget-periods', [
            'name' => 'Anggaran 2026', 'fiscal_year' => 2026,
            'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
        ], $ctx['headers'])->json('data');

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        $this->postJson("/api/budget-submissions/{$submission['id']}/submit", [], $ctx['headers'])
            ->assertJsonPath('data.status', 'approved');

        // Editing lines on approved submission must fail
        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [['account_id' => $ctx['account']->id, 'amount' => 999]],
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_unauthorized_role_cannot_manage_period(): void
    {
        $ctx = $this->setUpTenant(role: 'viewer');

        // viewer has budgets.view? No — viewer role only has dashboard/reports/fiscal.
        $this->postJson('/api/budget-periods', [
            'name' => 'X', 'fiscal_year' => 2026,
            'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
        ], $ctx['headers'])->assertStatus(403);
    }
}
