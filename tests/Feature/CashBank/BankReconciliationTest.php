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
}

