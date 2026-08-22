<?php

namespace App\Modules\Reports\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Laba Ditahan (Fase 9 T9.1).
 *
 * Definisi konsisten dengan Neraca (BalanceSheetService::calculateCurrentYearProfitLoss):
 * laba/rugi = Σ signedAmount(revenue) − Σ signedAmount(expense) atas jurnal posted.
 *  - beginning_retained_earnings = akumulasi laba/rugi seluruh periode SEBELUM start_date
 *    (laba ditahan awal / saldo tahun-tahun sebelumnya).
 *  - net_income = laba/rugi dalam periode [start_date, end_date].
 *  - ending_retained_earnings = beginning + net_income.
 *
 * Tidak mengasumsikan closing entries: laba ditahan diturunkan langsung dari akumulasi
 * P/L (audit-safe & internally consistent dengan Neraca yang memisahkan "Laba Tahun Berjalan").
 */
class RetainedEarningsService
{
    public function __construct(
        private readonly LedgerBalanceCalculator $balanceCalculator,
        private readonly ?ReportVisibilityService $visibilityService = null,
    ) {}

    /**
     * @param  array{start_date:string, end_date:string, department_id?:int|null, project_id?:int|null}  $filters
     * @return array<string,mixed>
     */
    public function getReport(array $filters): array
    {
        $start = (string) $filters['start_date'];
        $end = (string) $filters['end_date'];

        $beginning = $this->profitLossUpTo($filters, $start, inclusive: false);
        $ending = $this->profitLossUpTo($filters, $end, inclusive: true);
        $netIncome = $ending - $beginning;

        return [
            'valid' => true,
            'filter' => [
                'start_date' => $start,
                'end_date' => $end,
                'department_id' => $filters['department_id'] ?? null,
                'project_id' => $filters['project_id'] ?? null,
            ],
            'beginning_retained_earnings' => round($beginning, 2),
            'net_income' => round($netIncome, 2),
            'ending_retained_earnings' => round($ending, 2),
        ];
    }

    /**
     * Akumulasi laba/rugi (revenue − expense) untuk jurnal posted s/d tanggal batas.
     *
     * @param  array<string,mixed>  $filters
     */
    private function profitLossUpTo(array $filters, string $boundary, bool $inclusive): float
    {
        $query = $this->applyDimensionFilters($this->baseReportableJournalLineQuery(), $filters)
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->whereIn('coa.account_type', ['revenue', 'expense'])
            ->whereDate('je.journal_date', $inclusive ? '<=' : '<', $boundary)
            ->select(['coa.account_type', 'coa.normal_balance', 'jel.debit', 'jel.credit'])
            ->get();

        $revenue = 0.0;
        $expense = 0.0;

        foreach ($query as $r) {
            $amount = $this->balanceCalculator->signedAmount((float) ($r->debit ?? 0), (float) ($r->credit ?? 0), (string) $r->normal_balance);
            if ((string) $r->account_type === 'revenue') {
                $revenue += $amount;
            } else {
                $expense += $amount;
            }
        }

        return $revenue - $expense;
    }

    private function baseReportableJournalLineQuery(): Builder
    {
        $query = DB::connection('tenant')->table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', '=', 'posted')
            ->where('je.is_obsolete', '=', 0);

        if ($this->visibilityService) {
            $query->whereIn('je.status', (array) config('report_visibility.reportable_journal_statuses', ['posted']));
        }

        return $query;
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function applyDimensionFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['department_id'])) {
            $query->where('jel.department_id', '=', (int) $filters['department_id']);
        }
        if (! empty($filters['project_id'])) {
            $query->where('jel.project_id', '=', (int) $filters['project_id']);
        }

        return $query;
    }
}
