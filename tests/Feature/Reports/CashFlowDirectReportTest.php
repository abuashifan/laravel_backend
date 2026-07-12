<?php

namespace Tests\Feature\Reports;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use Tests\Feature\Journal\JournalTestCase;

class CashFlowDirectReportTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/cash-flow-direct')->assertStatus(401);
    }

    public function test_permission_denied(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/cash-flow-direct?start_date=2026-02-01&end_date=2026-02-28', $ctx['headers'])->assertStatus(403);
    }

    public function test_requires_dates(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $this->getJson('/api/reports/cash-flow-direct', $ctx['headers'])->assertStatus(422);
    }

    public function test_direct_sections_grouped_by_contra_account(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cash = (int) $ctx['accounts']['debit'];

        // Contra accounts with explicit cash_flow_section.
        $sales = ChartOfAccount::query()->create(['account_code' => '4100', 'account_name' => 'Sales Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'cash_flow_section' => 'operating', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false])->id;
        $equipment = ChartOfAccount::query()->create(['account_code' => '1500', 'account_name' => 'Equipment', 'account_type' => 'asset', 'normal_balance' => 'debit', 'cash_flow_section' => 'investing', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false])->id;

        // Opening cash before period: 100.
        $this->postedJournal('CF-0', '2026-01-05', [[$cash, 100, 0], [$sales, 0, 100]]);
        // Cash received from sales: 1000 (operating in).
        $this->postedJournal('CF-1', '2026-02-10', [[$cash, 1000, 0], [$sales, 0, 1000]]);
        // Cash paid for equipment: 400 (investing out).
        $this->postedJournal('CF-2', '2026-02-15', [[$equipment, 400, 0], [$cash, 0, 400]]);

        $res = $this->getJson('/api/reports/cash-flow-direct?start_date=2026-02-01&end_date=2026-02-28', $ctx['headers'])->assertStatus(200);

        $this->assertSame(100.0, (float) $res->json('data.summary.opening_cash_balance'));
        $this->assertSame(1000.0, (float) $res->json('data.summary.cash_in'));
        $this->assertSame(400.0, (float) $res->json('data.summary.cash_out'));
        $this->assertSame(600.0, (float) $res->json('data.summary.net_cash_flow'));
        $this->assertSame(700.0, (float) $res->json('data.summary.ending_cash_balance'));

        $sections = collect($res->json('data.sections'))->keyBy('key');
        $operating = $sections['operating'];
        $this->assertSame(1000.0, (float) $operating['subtotal_net']);
        $this->assertSame('Sales Revenue', $operating['lines'][0]['account_name']);
        $this->assertSame(1000.0, (float) $operating['lines'][0]['cash_in']);

        $investing = $sections['investing'];
        $this->assertSame(-400.0, (float) $investing['subtotal_net']);
        $this->assertSame(400.0, (float) $investing['lines'][0]['cash_out']);
    }

    public function test_no_cash_accounts_returns_note(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        // Remove the default cash flag so there are no cash accounts.
        ChartOfAccount::query()->where('is_cash_bank', true)->update(['is_cash_bank' => false]);

        $res = $this->getJson('/api/reports/cash-flow-direct?start_date=2026-02-01&end_date=2026-02-28', $ctx['headers'])->assertStatus(200);
        $this->assertTrue((bool) $res->json('data.notes.no_cash_accounts'));
        $this->assertSame([], $res->json('data.sections'));
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
