<?php

namespace App\Modules\Reports\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Perubahan Ekuitas Pemilik (Fase 9 T9.2).
 *
 * Per akun ekuitas: saldo awal (sebelum start_date) + pergerakan periode (setoran/penarikan)
 * = saldo akhir (s/d end_date). Ditambah baris sintetis "Laba/Rugi Periode Berjalan" yang
 * nilainya = net income periode (revenue − expense), konsisten dengan baris ekuitas di Neraca.
 */
class EquityChangesService
{
    public function __construct(
        private readonly LedgerBalanceCalculator $balanceCalculator,
        private readonly RetainedEarningsService $retainedEarnings,
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

        $opening = $this->totalsUpTo($filters, $start, inclusive: false);
        $ending = $this->totalsUpTo($filters, $end, inclusive: true);

        $accounts = DB::connection('tenant')->table('chart_of_accounts')
            ->where('account_type', '=', 'equity')
            ->orderBy('account_code')
            ->get(['id', 'account_code', 'account_name', 'normal_balance']);

        $rows = [];
        $openingTotal = 0.0;
        $movementTotal = 0.0;
        $closingTotal = 0.0;

        foreach ($accounts as $acc) {
            $accountId = (int) $acc->id;
            $normalBalance = (string) $acc->normal_balance;

            $openingBalance = $this->signedFromTotals($opening[$accountId] ?? null, $normalBalance);
            $closingBalance = $this->signedFromTotals($ending[$accountId] ?? null, $normalBalance);
            $movement = $closingBalance - $openingBalance;

            // Skip akun ekuitas tanpa aktivitas & saldo nol.
            if (abs($openingBalance) < 0.0000001 && abs($closingBalance) < 0.0000001 && abs($movement) < 0.0000001) {
                continue;
            }

            $rows[] = [
                'account_id' => $accountId,
                'account_code' => (string) $acc->account_code,
                'account_name' => (string) $acc->account_name,
                'opening_balance' => round($openingBalance, 2),
                'movement' => round($movement, 2),
                'closing_balance' => round($closingBalance, 2),
                'is_current_earnings' => false,
            ];

            $openingTotal += $openingBalance;
            $movementTotal += $movement;
            $closingTotal += $closingBalance;
        }

        // Baris sintetis: laba/rugi periode berjalan (net income).
        $re = $this->retainedEarnings->getReport($filters);
        $netIncome = (float) $re['net_income'];

        $rows[] = [
            'account_id' => null,
            'account_code' => null,
            'account_name' => 'Laba / Rugi Periode Berjalan',
            'opening_balance' => 0.0,
            'movement' => round($netIncome, 2),
            'closing_balance' => round($netIncome, 2),
            'is_current_earnings' => true,
        ];
        $movementTotal += $netIncome;
        $closingTotal += $netIncome;

        return [
            'valid' => true,
            'filter' => [
                'start_date' => $start,
                'end_date' => $end,
                'department_id' => $filters['department_id'] ?? null,
                'project_id' => $filters['project_id'] ?? null,
            ],
            'rows' => $rows,
            'totals' => [
                'opening_total' => round($openingTotal, 2),
                'movement_total' => round($movementTotal, 2),
                'closing_total' => round($closingTotal, 2),
            ],
        ];
    }

    /**
     * @param  array{debit:float,credit:float}|null  $totals
     */
    private function signedFromTotals(?array $totals, string $normalBalance): float
    {
        if ($totals === null) {
            return 0.0;
        }

        return $this->balanceCalculator->signedAmount((float) $totals['debit'], (float) $totals['credit'], $normalBalance);
    }

    /**
     * Total debit/credit per akun ekuitas s/d tanggal batas.
     *
     * @param  array<string,mixed>  $filters
     * @return array<int,array{debit:float,credit:float}>
     */
    private function totalsUpTo(array $filters, string $boundary, bool $inclusive): array
    {
        $rows = $this->applyDimensionFilters($this->baseReportableJournalLineQuery(), $filters)
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('coa.account_type', '=', 'equity')
            ->whereDate('je.journal_date', $inclusive ? '<=' : '<', $boundary)
            ->select(['jel.account_id', 'jel.debit', 'jel.credit'])
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $accountId = (int) $r->account_id;
            $map[$accountId] ??= ['debit' => 0.0, 'credit' => 0.0];
            $map[$accountId]['debit'] += (float) ($r->debit ?? 0);
            $map[$accountId]['credit'] += (float) ($r->credit ?? 0);
        }

        return $map;
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
