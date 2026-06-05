<?php

namespace Tests\Feature\CashBank;

use App\Models\Tenant\JournalEntry;
use Tests\Feature\Journal\JournalTestCase;

class CashBankAccountStatementTest extends JournalTestCase
{
    public function test_account_statement_returns_opening_period_and_ending_balance(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cashId = (int) $ctx['accounts']['debit'];
        $revenueId = (int) $ctx['accounts']['credit'];

        // opening: +500 before start_date
        $j0 = JournalEntry::query()->create([
            'journal_number' => 'JV-OPEN-1',
            'journal_date' => '2025-12-31',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $j0->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 500, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 500, 'line_order' => 2],
        ]);

        // period: +1000
        $j1 = JournalEntry::query()->create([
            'journal_number' => 'JV-PER-1',
            'journal_date' => '2026-01-10',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $j1->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 1000, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 1000, 'line_order' => 2],
        ]);

        $res = $this->getJson('/api/cash-bank/reports/account-statement?cash_bank_account_id='.$cashId.'&start_date=2026-01-01&end_date=2026-01-31', $ctx['headers']);
        $res->assertStatus(200);
        $this->assertSame(500.0, (float) $res->json('data.opening_balance'));
        $this->assertSame(1500.0, (float) $res->json('data.ending_balance'));
        $this->assertCount(1, (array) $res->json('data.lines'));
    }
}
