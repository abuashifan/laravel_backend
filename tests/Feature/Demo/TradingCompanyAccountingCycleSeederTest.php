<?php

namespace Tests\Feature\Demo;

use Database\Seeders\tenant\TradingCompanyAccountingCycleSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Journal\JournalTestCase;

class TradingCompanyAccountingCycleSeederTest extends JournalTestCase
{
    public function test_trading_company_demo_seeder_creates_balanced_reportable_data_and_is_rerunnable_with_reset(): void
    {
        $this->setUpTenant(role: 'owner');

        $first = (new TradingCompanyAccountingCycleSeeder())->seed(2025, true);

        $this->assertTrue((bool) $first['trial_balance']['balanced']);
        $this->assertSame(0, (int) $first['trial_balance']['unbalanced_journals']);
        $this->assertGreaterThan(0, (int) $first['journal_entries']);

        $opening = DB::connection('tenant')->table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->where('je.source_number', 'OB-NDS-2025')
            ->selectRaw('COALESCE(SUM(jel.debit), 0) as debit, COALESCE(SUM(jel.credit), 0) as credit')
            ->first();
        $this->assertSame(327000000.0, (float) $opening->debit);
        $this->assertSame(327000000.0, (float) $opening->credit);

        $unbalanced = DB::connection('tenant')->table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->where('je.source_type', 'trading_company_accounting_cycle_2025')
            ->where('je.status', 'posted')
            ->groupBy('je.id')
            ->havingRaw('ABS(COALESCE(SUM(jel.debit), 0) - COALESCE(SUM(jel.credit), 0)) >= 0.01')
            ->count();
        $this->assertSame(0, $unbalanced);

        $trial = DB::connection('tenant')->table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->where('je.status', 'posted')
            ->where('je.is_obsolete', 0)
            ->selectRaw('COALESCE(SUM(jel.debit), 0) as debit, COALESCE(SUM(jel.credit), 0) as credit')
            ->first();
        $this->assertSame((float) $trial->debit, (float) $trial->credit);

        $profitLossRows = DB::connection('tenant')->table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->whereIn('coa.account_type', ['revenue', 'expense'])
            ->count();
        $this->assertGreaterThan(0, $profitLossRows);

        $arGl = $this->normalDebitBalance('1120');
        $apGl = $this->normalCreditBalance('2100');
        $arDocs = (float) DB::connection('tenant')->table('sales_invoices')->sum('balance_due');
        $apDocs = (float) DB::connection('tenant')->table('vendor_bills')->sum('balance_due');
        $this->assertGreaterThan(0, $arGl);
        $this->assertGreaterThan(0, $apGl);
        $this->assertGreaterThan(0, $arDocs);
        $this->assertGreaterThan(0, $apDocs);

        $second = (new TradingCompanyAccountingCycleSeeder())->seed(2025, true);
        $this->assertTrue((bool) $second['trial_balance']['balanced']);
        $this->assertSame($first['journal_entries'], $second['journal_entries']);
    }

    private function normalDebitBalance(string $accountCode): float
    {
        $row = $this->accountSums($accountCode);
        return (float) $row->debit - (float) $row->credit;
    }

    private function normalCreditBalance(string $accountCode): float
    {
        $row = $this->accountSums($accountCode);
        return (float) $row->credit - (float) $row->debit;
    }

    private function accountSums(string $accountCode): object
    {
        return DB::connection('tenant')->table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->where('je.is_obsolete', 0)
            ->where('coa.account_code', $accountCode)
            ->selectRaw('COALESCE(SUM(jel.debit), 0) as debit, COALESCE(SUM(jel.credit), 0) as credit')
            ->first();
    }
}
