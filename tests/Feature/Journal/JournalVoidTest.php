<?php

namespace Tests\Feature\Journal;

use App\Models\Tenant\JournalEntry;

class JournalVoidTest extends JournalTestCase
{
    public function test_draft_journal_can_be_voided(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $create = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'Void me',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 10],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 10],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $id = (int) $create->json('data.id');

        $void = $this->postJson("/api/journals/{$id}/void", ['reason' => 'Mistake'], $ctx['headers'])->assertStatus(200);
        $void->assertJsonPath('data.status', 'void');
        $this->assertNotEmpty($void->json('data.voided_at'));
    }

    public function test_void_requires_reason_if_setting_enabled(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
            'require_void_reason' => true,
        ]);

        $create = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'Need reason',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 10],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 10],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $id = (int) $create->json('data.id');

        $void = $this->postJson("/api/journals/{$id}/void", ['reason' => ''], $ctx['headers']);
        $void->assertStatus(422);
        $void->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_void_journal_not_visible_in_index_by_default(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $create = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'Index hide',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 1],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 1],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $id = (int) $create->json('data.id');
        $this->postJson("/api/journals/{$id}/void", ['reason' => 'Mistake'], $ctx['headers'])->assertStatus(200);

        $index = $this->getJson('/api/journals', $ctx['headers'])->assertStatus(200);
        $this->assertCount(0, $index->json('data'));
    }

    public function test_void_journal_cannot_be_voided_again(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $create = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'Void twice',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 1],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 1],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $id = (int) $create->json('data.id');
        $this->postJson("/api/journals/{$id}/void", ['reason' => 'Mistake'], $ctx['headers'])->assertStatus(200);

        $again = $this->postJson("/api/journals/{$id}/void", ['reason' => 'Again'], $ctx['headers']);
        $again->assertStatus(422);
        $again->assertJsonPath('code', 'TRANSACTION_ALREADY_VOID');
    }

    public function test_system_generated_journal_cannot_be_voided_directly(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $j = JournalEntry::query()->create([
            'journal_number' => 'SYS-000001',
            'journal_date' => now()->toDateString(),
            'status' => 'draft',
            'revision_no' => 1,
            'source_type' => 'sales_invoice',
            'source_id' => '1',
            'source_number' => 'SI-000001',
            'source_revision' => 1,
            'source_module' => 'sales',
            'is_system_generated' => true,
            'is_obsolete' => false,
        ]);

        $j->lines()->createMany([
            ['account_id' => $ctx['accounts']['debit'], 'debit' => 10, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $ctx['accounts']['credit'], 'debit' => 0, 'credit' => 10, 'line_order' => 2],
        ]);

        $res = $this->postJson("/api/journals/{$j->id}/void", ['reason' => 'Nope'], $ctx['headers']);
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'SYSTEM_GENERATED_READ_ONLY');
    }
}

