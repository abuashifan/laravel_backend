<?php

namespace Tests\Feature\Reports;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use Tests\Feature\Journal\JournalTestCase;

class MultiPeriodReportTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/profit-loss/multi-period')->assertStatus(401);
        $this->getJson('/api/reports/balance-sheet/multi-period')->assertStatus(401);
    }

    public function test_permission_denied(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/profit-loss/multi-period?'.$this->twoPeriods(), $ctx['headers'])->assertStatus(403);
    }

    public function test_requires_periods(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $this->getJson('/api/reports/profit-loss/multi-period', $ctx['headers'])->assertStatus(422);
    }

    public function test_rejects_more_than_twelve_periods(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $periods = [];
        for ($i = 0; $i < 13; $i++) {
            $periods[] = ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'];
        }
        $this->getJson('/api/reports/profit-loss/multi-period?'.http_build_query(['periods' => $periods]), $ctx['headers'])
            ->assertStatus(422);
    }

    public function test_profit_loss_multi_period_columns_match_single_period(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cash = (int) $ctx['accounts']['debit'];
        $revenue = (int) $ctx['accounts']['credit'];
        $expense = ChartOfAccount::query()->create(['account_code' => '5000', 'account_name' => 'Expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false])->id;

        // Jan: revenue 1000, expense 300 (net 700). Feb: revenue 500 (net 500).
        $this->postedJournal('MP-1', '2026-01-10', [[$cash, 1000, 0], [$revenue, 0, 1000]]);
        $this->postedJournal('MP-2', '2026-01-11', [[$expense, 300, 0], [$cash, 0, 300]]);
        $this->postedJournal('MP-3', '2026-02-10', [[$cash, 500, 0], [$revenue, 0, 500]]);

        $res = $this->getJson('/api/reports/profit-loss/multi-period?'.$this->twoPeriods(), $ctx['headers'])->assertStatus(200);

        $res->assertJsonPath('data.report_type', 'profit_loss');
        $periods = $res->json('data.periods');
        $this->assertCount(2, $periods);
        $this->assertSame('Jan', $periods[0]['label']);

        // summary_totals net per column.
        $summary = $res->json('data.summary_totals');
        $this->assertSame(700.0, (float) $summary[0]['net_profit_or_loss']);
        $this->assertSame(500.0, (float) $summary[1]['net_profit_or_loss']);

        // Revenue account row values per period [1000, 500].
        $sections = collect($res->json('data.sections'))->keyBy('key');
        $revRow = collect($sections['revenue']['rows'])->firstWhere('account_id', $revenue);
        $this->assertSame([1000.0, 500.0], array_map('floatval', $revRow['values']));
        $expRow = collect($sections['expense']['rows'])->firstWhere('account_id', $expense);
        $this->assertSame([300.0, 0.0], array_map('floatval', $expRow['values']));

        // Column == single-period report for the same period.
        $single = $this->getJson('/api/reports/profit-loss?start_date=2026-02-01&end_date=2026-02-28', $ctx['headers'])->assertStatus(200);
        $this->assertSame((float) $single->json('data.totals.net_profit_or_loss'), (float) $summary[1]['net_profit_or_loss']);
    }

    public function test_balance_sheet_multi_period_columns_match_single_period(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cash = (int) $ctx['accounts']['debit'];
        $revenue = (int) $ctx['accounts']['credit'];
        $capital = ChartOfAccount::query()->create(['account_code' => '3000', 'account_name' => 'Capital', 'account_type' => 'equity', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false])->id;

        $this->postedJournal('BSMP-CAP', '2026-01-05', [[$cash, 5000, 0], [$capital, 0, 5000]]);
        $this->postedJournal('BSMP-REV', '2026-02-10', [[$cash, 2000, 0], [$revenue, 0, 2000]]);

        $res = $this->getJson('/api/reports/balance-sheet/multi-period?'.$this->twoPeriods(), $ctx['headers'])->assertStatus(200);

        $res->assertJsonPath('data.report_type', 'balance_sheet');
        $summary = $res->json('data.summary_totals');

        // Jan as-of: assets 5000, equity 5000. Feb as-of: assets 7000, equity 7000 (capital 5000 + PL 2000).
        $this->assertSame(5000.0, (float) $summary[0]['total_assets']);
        $this->assertSame(7000.0, (float) $summary[1]['total_assets']);
        $this->assertTrue((bool) $summary[1]['is_balanced']);

        // Column == single balance-sheet as_of that period end.
        $singleFeb = $this->getJson('/api/reports/balance-sheet?as_of_date=2026-02-28', $ctx['headers'])->assertStatus(200);
        $this->assertSame((float) $singleFeb->json('data.totals.total_equity'), (float) $summary[1]['total_equity']);
        $this->assertSame((float) $singleFeb->json('data.totals.total_assets'), (float) $summary[1]['total_assets']);
    }

    private function twoPeriods(): string
    {
        return http_build_query(['periods' => [
            ['start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'label' => 'Jan'],
            ['start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'label' => 'Feb'],
        ]]);
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
