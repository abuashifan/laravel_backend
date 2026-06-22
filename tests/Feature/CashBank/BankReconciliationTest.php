<?php

namespace Tests\Feature\CashBank;

use App\Models\Tenant\JournalEntry;
use Tests\Feature\Journal\JournalTestCase;

class BankReconciliationTest extends JournalTestCase
{
    public function test_can_create_reconciliation_and_lines_are_generated_from_posted_cash_bank_journals(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cashId = (int) $ctx['accounts']['debit'];
        $revenueId = (int) $ctx['accounts']['credit'];

        $j = JournalEntry::query()->create([
            'journal_number' => 'JV-CASH-1',
            'journal_date' => '2026-01-10',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $j->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 1000, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 1000, 'line_order' => 2],
        ]);

        $payload = [
            'cash_bank_account_id' => $cashId,
            'statement_start_date' => '2026-01-01',
            'statement_end_date' => '2026-01-31',
            'statement_opening_balance' => 0,
            'statement_ending_balance' => 1000,
        ];

        $res = $this->postJson('/api/cash-bank/bank-reconciliations', $payload, $ctx['headers']);
        $res->assertStatus(201);
        $res->assertJsonPath('data.status', 'draft');
        $this->assertNotEmpty($res->json('data.reconciliation_number'));
        $this->assertCount(1, (array) $res->json('data.lines'));
    }

    public function test_refresh_preserves_cleared_state_and_cleared_date_must_be_in_period(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cashId = (int) $ctx['accounts']['debit'];
        $revenueId = (int) $ctx['accounts']['credit'];
        $journal = JournalEntry::query()->create([
            'journal_number' => 'JV-CASH-REFRESH',
            'journal_date' => '2026-01-10',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $journal->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 1000, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 1000, 'line_order' => 2],
        ]);

        $created = $this->postJson('/api/cash-bank/bank-reconciliations', [
            'cash_bank_account_id' => $cashId,
            'statement_start_date' => '2026-01-01',
            'statement_end_date' => '2026-01-31',
            'statement_opening_balance' => 0,
            'statement_ending_balance' => 1000,
        ], $ctx['headers'])->assertCreated();

        $id = (int) $created->json('data.id');
        $lineId = (int) $created->json('data.lines.0.id');

        $this->postJson('/api/cash-bank/bank-reconciliations/'.$id.'/mark-lines', [
            'line_ids' => [$lineId],
            'cleared' => true,
            'cleared_date' => '2026-02-01',
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cleared_date');

        $this->postJson('/api/cash-bank/bank-reconciliations/'.$id.'/mark-lines', [
            'line_ids' => [$lineId],
            'cleared' => true,
            'cleared_date' => '2026-01-31',
        ], $ctx['headers'])->assertOk();

        $this->postJson('/api/cash-bank/bank-reconciliations/'.$id.'/refresh-lines', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.lines.0.is_cleared', true)
            ->assertJsonPath('data.lines.0.cleared_date', '2026-01-31T00:00:00.000000Z');
    }

    public function test_finalize_requires_zero_difference_and_reopen_requires_reason(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cashId = (int) $ctx['accounts']['debit'];
        $revenueId = (int) $ctx['accounts']['credit'];
        $journal = JournalEntry::query()->create([
            'journal_number' => 'JV-CASH-FINALIZE',
            'journal_date' => '2026-01-15',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $journal->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 1000, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 1000, 'line_order' => 2],
        ]);

        $created = $this->postJson('/api/cash-bank/bank-reconciliations', [
            'cash_bank_account_id' => $cashId,
            'statement_start_date' => '2026-01-01',
            'statement_end_date' => '2026-01-31',
            'statement_opening_balance' => 500,
            'statement_ending_balance' => 1500,
        ], $ctx['headers'])->assertCreated();

        $id = (int) $created->json('data.id');
        $lineId = (int) $created->json('data.lines.0.id');

        $this->postJson('/api/cash-bank/bank-reconciliations/'.$id.'/finalize', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'RECONCILIATION_NOT_BALANCED');

        $this->postJson('/api/cash-bank/bank-reconciliations/'.$id.'/mark-lines', [
            'line_ids' => [$lineId],
            'cleared' => true,
            'cleared_date' => '2026-01-31',
        ], $ctx['headers'])->assertOk();

        $this->postJson('/api/cash-bank/bank-reconciliations/'.$id.'/finalize', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'finalized');

        $this->patchJson('/api/cash-bank/bank-reconciliations/'.$id, [
            'statement_ending_balance' => 1600,
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'RECONCILIATION_NOT_EDITABLE');

        $this->postJson('/api/cash-bank/bank-reconciliations/'.$id.'/reopen', [
            'reason' => 'short',
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson('/api/cash-bank/bank-reconciliations/'.$id.'/reopen', [
            'reason' => 'Koreksi mutasi bank yang belum masuk.',
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
    }
}
