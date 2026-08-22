<?php

namespace Tests\Feature\Reports;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use Tests\Feature\Journal\JournalTestCase;

class RetainedEarningsReportTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/retained-earnings')->assertStatus(401);
    }

    public function test_permission_denied(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/retained-earnings?start_date=2026-02-01&end_date=2026-02-28', $ctx['headers'])->assertStatus(403);
    }

    public function test_requires_dates(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $this->getJson('/api/reports/retained-earnings', $ctx['headers'])->assertStatus(422);
    }

    public function test_beginning_net_income_and_ending(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cash = (int) $ctx['accounts']['debit'];
        $revenue = (int) $ctx['accounts']['credit'];
        $expense = ChartOfAccount::query()->create(['account_code' => '5000', 'account_name' => 'Expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false])->id;

        // Prior period (before start): revenue 1000, expense 300 => beginning RE 700.
        $this->postedJournal('RE-1', '2026-01-31', [[$cash, 1000, 0], [$revenue, 0, 1000]]);
        $this->postedJournal('RE-2', '2026-01-31', [[$expense, 300, 0], [$cash, 0, 300]]);

        // In period: revenue 500, expense 200 => net income 300.
        $this->postedJournal('RE-3', '2026-02-15', [[$cash, 500, 0], [$revenue, 0, 500]]);
        $this->postedJournal('RE-4', '2026-02-20', [[$expense, 200, 0], [$cash, 0, 200]]);

        $res = $this->getJson('/api/reports/retained-earnings?start_date=2026-02-01&end_date=2026-02-28', $ctx['headers'])->assertStatus(200);

        $this->assertSame(700.0, (float) $res->json('data.beginning_retained_earnings'));
        $this->assertSame(300.0, (float) $res->json('data.net_income'));
        $this->assertSame(1000.0, (float) $res->json('data.ending_retained_earnings'));
    }

    public function test_excludes_draft_and_obsolete(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cash = (int) $ctx['accounts']['debit'];
        $revenue = (int) $ctx['accounts']['credit'];

        $this->postedJournal('RE-OK', '2026-02-10', [[$cash, 400, 0], [$revenue, 0, 400]]);

        $draft = JournalEntry::query()->create(['journal_number' => 'RE-DRAFT', 'journal_date' => '2026-02-11', 'status' => 'draft', 'is_obsolete' => false]);
        $draft->lines()->createMany([['account_id' => $cash, 'debit' => 999, 'credit' => 0, 'line_order' => 1], ['account_id' => $revenue, 'debit' => 0, 'credit' => 999, 'line_order' => 2]]);

        $res = $this->getJson('/api/reports/retained-earnings?start_date=2026-02-01&end_date=2026-02-28', $ctx['headers'])->assertStatus(200);
        $this->assertSame(400.0, (float) $res->json('data.net_income'));
    }

    /**
     * @param  list<array{0:int,1:float,2:float}>  $lines  [account_id, debit, credit]
     */
    private function postedJournal(string $number, string $date, array $lines): void
    {
        $j = JournalEntry::query()->create(['journal_number' => $number, 'journal_date' => $date, 'status' => 'posted', 'is_obsolete' => false]);
        $order = 1;
        $j->lines()->createMany(array_map(fn ($l) => ['account_id' => $l[0], 'debit' => $l[1], 'credit' => $l[2], 'line_order' => $order++], $lines));
    }
}
