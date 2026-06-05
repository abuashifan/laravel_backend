<?php

namespace Tests\Feature\Journal;

use App\Models\FiscalYear;

class JournalPostingTest extends JournalTestCase
{
    public function test_draft_balanced_journal_can_be_posted(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $create = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'To Post',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 100],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 100],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $id = (int) $create->json('data.id');

        $post = $this->postJson("/api/journals/{$id}/post", [], $ctx['headers'])->assertStatus(200);
        $post->assertJsonPath('data.status', 'posted');
        $this->assertNotEmpty($post->json('data.posted_at'));
        $this->assertNotEmpty($post->json('data.posted_by'));
    }

    public function test_posted_journal_cannot_be_posted_again(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $create = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'Already Posted',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 10],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 10],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $id = (int) $create->json('data.id');
        $this->postJson("/api/journals/{$id}/post", [], $ctx['headers'])->assertStatus(200);

        $again = $this->postJson("/api/journals/{$id}/post", [], $ctx['headers']);
        $again->assertStatus(422);
        $again->assertJsonPath('code', 'TRANSACTION_ALREADY_POSTED');
    }

    public function test_void_journal_cannot_be_posted(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $create = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'Void then Post',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 10],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 10],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $id = (int) $create->json('data.id');
        $this->postJson("/api/journals/{$id}/void", ['reason' => 'Mistake'], $ctx['headers'])->assertStatus(200);

        $post = $this->postJson("/api/journals/{$id}/post", [], $ctx['headers']);
        $post->assertStatus(422);
        $post->assertJsonPath('code', 'TRANSACTION_ALREADY_VOID');
    }

    public function test_workflow_draft_approve_post_requires_approved_before_post(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_approve_post',
            'auto_post_transactions' => false,
            'approval_enabled' => true,
        ]);

        $create = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'Needs Approval',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 20],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 20],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $id = (int) $create->json('data.id');

        $post = $this->postJson("/api/journals/{$id}/post", [], $ctx['headers']);
        $post->assertStatus(422);
        $post->assertJsonPath('code', 'JOURNAL_REQUIRES_APPROVAL');

        $this->postJson("/api/journals/{$id}/approve", [], $ctx['headers'])->assertStatus(200);
        $this->postJson("/api/journals/{$id}/post", [], $ctx['headers'])->assertStatus(200);
    }

    public function test_fiscal_year_closed_blocks_post(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $date = now()->toDateString();
        $create = $this->postJson('/api/journals', [
            'journal_date' => $date,
            'description' => 'Closed FY',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 10],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 10],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $id = (int) $create->json('data.id');

        // Close fiscal year after draft created to ensure post is blocked by date guard.
        FiscalYear::query()->where('company_id', $ctx['company']->id)->delete();
        FiscalYear::query()->create([
            'company_id' => $ctx['company']->id,
            'year' => (int) now()->format('Y'),
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'closed',
            'is_active' => false,
        ]);

        $post = $this->postJson("/api/journals/{$id}/post", [], $ctx['headers']);
        $post->assertStatus(422);
        $post->assertJsonPath('code', 'FISCAL_YEAR_CLOSED');
    }
}
