<?php

namespace Tests\Feature\Reports;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use Tests\Feature\Journal\JournalTestCase;

class EquityChangesReportTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/equity-changes')->assertStatus(401);
    }

    public function test_permission_denied(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/equity-changes?start_date=2026-02-01&end_date=2026-02-28', $ctx['headers'])->assertStatus(403);
    }

    public function test_requires_dates(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $this->getJson('/api/reports/equity-changes', $ctx['headers'])->assertStatus(422);
    }

    public function test_opening_movement_closing_and_current_earnings_row(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cash = (int) $ctx['accounts']['debit'];
        $revenue = (int) $ctx['accounts']['credit'];
        $capital = ChartOfAccount::query()->create(['account_code' => '3000', 'account_name' => 'Capital', 'account_type' => 'equity', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false])->id;

        // Opening capital before Feb: 5000.
        $this->postedJournal('EQ-1', '2026-01-10', [[$cash, 5000, 0], [$capital, 0, 5000]]);
        // Additional contribution in Feb: 2000 => closing 7000.
        $this->postedJournal('EQ-2', '2026-02-05', [[$cash, 2000, 0], [$capital, 0, 2000]]);
        // Revenue in Feb => net income 500 (current earnings row).
        $this->postedJournal('EQ-3', '2026-02-15', [[$cash, 500, 0], [$revenue, 0, 500]]);

        $res = $this->getJson('/api/reports/equity-changes?start_date=2026-02-01&end_date=2026-02-28', $ctx['headers'])->assertStatus(200);

        $rows = collect($res->json('data.rows'));
        $capRow = $rows->firstWhere('account_code', '3000');
        $this->assertSame(5000.0, (float) $capRow['opening_balance']);
        $this->assertSame(2000.0, (float) $capRow['movement']);
        $this->assertSame(7000.0, (float) $capRow['closing_balance']);

        $earnRow = $rows->firstWhere('is_current_earnings', true);
        $this->assertNotNull($earnRow);
        $this->assertSame(500.0, (float) $earnRow['movement']);

        $this->assertSame(5000.0, (float) $res->json('data.totals.opening_total'));
        $this->assertSame(2500.0, (float) $res->json('data.totals.movement_total'));
        $this->assertSame(7500.0, (float) $res->json('data.totals.closing_total'));
    }

    /**
     * @param  list<array{0:int,1:float,2:float}>  $lines
     */
    private function postedJournal(string $number, string $date, array $lines): void
    {
        $j = JournalEntry::query()->create(['journal_number' => $number, 'journal_date' => $date, 'status' => 'posted', 'is_obsolete' => false]);
        $order = 1;
        $j->lines()->createMany(array_map(fn ($l) => ['account_id' => $l[0], 'debit' => $l[1], 'credit' => $l[2], 'line_order' => $order++], $lines));
    }
}
