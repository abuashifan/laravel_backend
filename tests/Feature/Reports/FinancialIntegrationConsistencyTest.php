<?php

namespace Tests\Feature\Reports;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Project;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Journal\JournalTestCase;

class FinancialIntegrationConsistencyTest extends JournalTestCase
{
    public function test_financial_summary_endpoint_and_cross_report_consistency_and_filters(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $cashId = (int) $ctx['accounts']['debit']; // cash, is_cash_bank=true
        $revenueId = (int) $ctx['accounts']['credit']; // revenue

        $capital = ChartOfAccount::query()->create([
            'account_code' => '3000',
            'account_name' => 'Capital',
            'account_type' => 'equity',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $expense = ChartOfAccount::query()->create([
            'account_code' => '5000',
            'account_name' => 'Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $deptA = Department::query()->create(['code' => 'A', 'name' => 'Dept A', 'is_active' => true]);
        $deptB = Department::query()->create(['code' => 'B', 'name' => 'Dept B', 'is_active' => true]);
        $projA = Project::query()->create(['code' => 'PA', 'name' => 'Project A', 'status' => 'active', 'is_active' => true]);
        $projB = Project::query()->create(['code' => 'PB', 'name' => 'Project B', 'status' => 'active', 'is_active' => true]);

        // Opening cash before period (Dept A)
        $jOpen = JournalEntry::query()->create([
            'journal_number' => 'JV-OPEN',
            'journal_date' => '2025-12-31',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jOpen->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 5000000, 'credit' => 0, 'line_order' => 1, 'department_id' => $deptA->id, 'project_id' => $projA->id],
            ['account_id' => $capital->id, 'debit' => 0, 'credit' => 5000000, 'line_order' => 2, 'department_id' => $deptA->id, 'project_id' => $projA->id],
        ]);

        // Period revenue (Dept A + Proj A)
        $jRev = JournalEntry::query()->create([
            'journal_number' => 'JV-REV',
            'journal_date' => '2026-01-10',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jRev->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 10000000, 'credit' => 0, 'line_order' => 1, 'department_id' => $deptA->id, 'project_id' => $projA->id],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 10000000, 'line_order' => 2, 'department_id' => $deptA->id, 'project_id' => $projA->id],
        ]);

        // Period expense (Dept A)
        $jExp = JournalEntry::query()->create([
            'journal_number' => 'JV-EXP',
            'journal_date' => '2026-01-11',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jExp->lines()->createMany([
            ['account_id' => $expense->id, 'debit' => 2000000, 'credit' => 0, 'line_order' => 1, 'department_id' => $deptA->id, 'project_id' => $projA->id],
            ['account_id' => $cashId, 'debit' => 0, 'credit' => 2000000, 'line_order' => 2, 'department_id' => $deptA->id, 'project_id' => $projA->id],
        ]);

        // Another dept/project movement (should be excluded by deptA/projA filter)
        $jOther = JournalEntry::query()->create([
            'journal_number' => 'JV-OTHER',
            'journal_date' => '2026-01-12',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jOther->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 111, 'credit' => 0, 'line_order' => 1, 'department_id' => $deptB->id, 'project_id' => $projB->id],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 111, 'line_order' => 2, 'department_id' => $deptB->id, 'project_id' => $projB->id],
        ]);

        // Excluded statuses
        $jDraft = JournalEntry::query()->create([
            'journal_number' => 'JV-DRF',
            'journal_date' => '2026-01-13',
            'status' => 'draft',
            'is_obsolete' => false,
        ]);
        $jDraft->lines()->createMany([
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 999999, 'line_order' => 1],
        ]);

        $jVoid = JournalEntry::query()->create([
            'journal_number' => 'JV-VOID',
            'journal_date' => '2026-01-14',
            'status' => 'void',
            'is_obsolete' => false,
        ]);
        $jVoid->lines()->createMany([
            ['account_id' => $expense->id, 'debit' => 888888, 'credit' => 0, 'line_order' => 1],
        ]);

        $jObs = JournalEntry::query()->create([
            'journal_number' => 'JV-OBS',
            'journal_date' => '2026-01-15',
            'status' => 'posted',
            'is_obsolete' => true,
        ]);
        $jObs->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 777777, 'credit' => 0, 'line_order' => 1],
        ]);

        $query = http_build_query([
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'as_of_date' => '2026-01-31',
            'department_id' => $deptA->id,
            'project_id' => $projA->id,
        ]);

        $sum = $this->getJson('/api/reports/financial-summary?'.$query, $ctx['headers'])->assertStatus(200);
        $sum->assertJsonPath('data.valid', true);

        // Profit & loss net must equal Balance Sheet current_year_profit_or_loss (with same filters & as_of_date)
        $plNet = (float) $sum->json('data.profit_loss.net_profit_or_loss');
        $bsPl = (float) $sum->json('data.balance_sheet.current_year_profit_or_loss');
        $this->assertSame($plNet, $bsPl);

        // Balance sheet should be balanced for this dataset: cash(13m) = capital(5m) + PL(8m)
        $this->assertTrue((bool) $sum->json('data.balance_sheet.is_balanced'));

        // Trial balance should be balanced too
        $tb = $this->getJson('/api/reports/trial-balance?start_date=2026-01-01&end_date=2026-01-31&department_id='.$deptA->id.'&project_id='.$projA->id, $ctx['headers'])
            ->assertStatus(200);
        $this->assertTrue((bool) $tb->json('data.totals.is_balanced'));

        // Cash flow ending balance should match cash account balance as of end_date for same filter
        $cfEnding = (float) $sum->json('data.cash_flow.ending_cash_balance');

        $cashSums = DB::connection('tenant')->table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', '=', 'posted')
            ->where('je.is_obsolete', '=', 0)
            ->where('jel.account_id', '=', $cashId)
            ->where('jel.department_id', '=', $deptA->id)
            ->where('jel.project_id', '=', $projA->id)
            ->whereDate('je.journal_date', '<=', '2026-01-31')
            ->selectRaw('COALESCE(SUM(jel.debit),0) as debit_sum, COALESCE(SUM(jel.credit),0) as credit_sum')
            ->first();

        $cashBalanceToEnd = (float) ($cashSums->debit_sum ?? 0) - (float) ($cashSums->credit_sum ?? 0);

        $this->assertSame($cashBalanceToEnd, $cfEnding);
    }

    /**
     * Fase 9: laba ditahan, perubahan ekuitas, dan arus kas langsung harus konsisten
     * dengan Neraca dan Arus Kas (tidak langsung) di atas dataset yang sama.
     */
    public function test_phase9_retained_earnings_equity_and_direct_cash_flow_are_consistent(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cashId = (int) $ctx['accounts']['debit'];   // is_cash_bank=true
        $revenueId = (int) $ctx['accounts']['credit']; // revenue
        // Revenue ikut seksi operasi untuk arus kas langsung.
        ChartOfAccount::query()->whereKey($revenueId)->update(['cash_flow_section' => 'operating']);

        $capital = ChartOfAccount::query()->create(['account_code' => '3000', 'account_name' => 'Capital', 'account_type' => 'equity', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false])->id;
        $expense = ChartOfAccount::query()->create(['account_code' => '5000', 'account_name' => 'Expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'cash_flow_section' => 'operating', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false])->id;
        $equipment = ChartOfAccount::query()->create(['account_code' => '1500', 'account_name' => 'Equipment', 'account_type' => 'asset', 'normal_balance' => 'debit', 'cash_flow_section' => 'investing', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false])->id;

        // Prior-year revenue (before period) => beginning retained earnings 1,000,000.
        $this->postedJournal('C9-PRIOR', '2025-06-01', [[$cashId, 1000000, 0], [$revenueId, 0, 1000000]]);
        // Prior-year capital injection => opening equity 5,000,000.
        $this->postedJournal('C9-CAP', '2025-12-31', [[$cashId, 5000000, 0], [$capital, 0, 5000000]]);
        // Period revenue 10,000,000 (operating cash in).
        $this->postedJournal('C9-REV', '2026-01-10', [[$cashId, 10000000, 0], [$revenueId, 0, 10000000]]);
        // Period expense 2,000,000 (operating cash out).
        $this->postedJournal('C9-EXP', '2026-01-11', [[$expense, 2000000, 0], [$cashId, 0, 2000000]]);
        // Period equipment purchase 3,000,000 (investing cash out).
        $this->postedJournal('C9-EQP', '2026-01-15', [[$equipment, 3000000, 0], [$cashId, 0, 3000000]]);

        $period = 'start_date=2026-01-01&end_date=2026-01-31';

        $re = $this->getJson('/api/reports/retained-earnings?'.$period, $ctx['headers'])->assertStatus(200)->json('data');
        $eq = $this->getJson('/api/reports/equity-changes?'.$period, $ctx['headers'])->assertStatus(200)->json('data');
        $bs = $this->getJson('/api/reports/balance-sheet?as_of_date=2026-01-31', $ctx['headers'])->assertStatus(200)->json('data');
        $cfInd = $this->getJson('/api/reports/cash-flow?'.$period, $ctx['headers'])->assertStatus(200)->json('data');
        $cfDir = $this->getJson('/api/reports/cash-flow-direct?'.$period, $ctx['headers'])->assertStatus(200)->json('data');

        // --- Retained earnings self-consistency + tie to balance sheet ---
        $this->assertSame(1000000.0, (float) $re['beginning_retained_earnings']);
        $this->assertSame(8000000.0, (float) $re['net_income']);
        $this->assertSame(9000000.0, (float) $re['ending_retained_earnings']);
        $this->assertSame(
            (float) $re['beginning_retained_earnings'] + (float) $re['net_income'],
            (float) $re['ending_retained_earnings'],
        );
        // Neraca "laba tahun berjalan" (kumulatif P/L s/d asOf) == laba ditahan akhir.
        $this->assertSame((float) $re['ending_retained_earnings'], (float) $bs['totals']['current_year_profit_or_loss']);

        // --- Equity changes ties to balance sheet equity ---
        $capRow = collect($eq['rows'])->firstWhere('account_code', '3000');
        $this->assertSame(5000000.0, (float) $capRow['closing_balance']);
        $earnRow = collect($eq['rows'])->firstWhere('is_current_earnings', true);
        $this->assertSame((float) $re['net_income'], (float) $earnRow['movement']);
        // total_equity Neraca = ekuitas closing (akun + laba berjalan) + laba ditahan awal (belum di akun ekuitas).
        $this->assertSame(
            (float) $bs['totals']['total_equity'],
            (float) $eq['totals']['closing_total'] + (float) $re['beginning_retained_earnings'],
        );

        // --- Direct vs indirect cash flow ---
        $this->assertSame((float) $cfInd['summary']['net_cash_flow'], (float) $cfDir['summary']['net_cash_flow']);
        $this->assertSame((float) $cfInd['summary']['ending_cash_balance'], (float) $cfDir['summary']['ending_cash_balance']);
        $this->assertSame(5000000.0, (float) $cfDir['summary']['net_cash_flow']);

        // Sum of direct section subtotals == net cash flow.
        $subtotalSum = array_sum(array_map(fn ($s) => (float) $s['subtotal_net'], $cfDir['sections']));
        $this->assertSame((float) $cfDir['summary']['net_cash_flow'], $subtotalSum);

        // Direct per-section subtotal matches indirect per-section net.
        $dirBySection = collect($cfDir['sections'])->keyBy('key');
        $this->assertSame((float) $cfInd['sections']['operating']['net'], (float) $dirBySection['operating']['subtotal_net']);
        $this->assertSame((float) $cfInd['sections']['investing']['net'], (float) $dirBySection['investing']['subtotal_net']);
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
