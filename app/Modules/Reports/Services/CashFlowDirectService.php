<?php

namespace App\Modules\Reports\Services;

use App\Modules\MasterData\Models\ChartOfAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Arus Kas Metode Langsung (Fase 9 T9.3).
 *
 * Metode langsung: penerimaan/pembayaran kas AKTUAL diklasifikasikan ke
 * operasi/investasi/pendanaan berdasar `cash_flow_section` akun lawan (contra),
 * lalu dirinci PER AKUN LAWAN (mis. "Kas dari Pendapatan Penjualan",
 * "Kas untuk Beban Gaji"). Berbeda dari metode tidak langsung yang menyesuaikan laba.
 * Basis perhitungan sama dengan CashFlowService::getSectionedCashFlow, hanya dirinci
 * per akun lawan alih-alih agregat per seksi.
 */
class CashFlowDirectService
{
    private const SECTION_LABELS = [
        'operating' => 'Aktivitas Operasi',
        'investing' => 'Aktivitas Investasi',
        'financing' => 'Aktivitas Pendanaan',
        'unclassified' => 'Belum Terklasifikasi',
    ];

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

        $cashAccounts = $this->getCashAccounts();
        $filterOut = [
            'start_date' => $start,
            'end_date' => $end,
            'department_id' => $filters['department_id'] ?? null,
            'project_id' => $filters['project_id'] ?? null,
        ];

        if ($cashAccounts === []) {
            return [
                'valid' => true,
                'filter' => $filterOut,
                'summary' => $this->emptySummary(),
                'sections' => [],
                'notes' => ['no_cash_accounts' => true],
            ];
        }

        $cashAccountIds = array_map(fn ($a) => (int) $a['account_id'], $cashAccounts);

        $summary = $this->buildSummary($filters, $cashAccounts, $start, $end);
        $sections = $this->buildDirectSections($filters, $cashAccountIds, $start, $end);

        return [
            'valid' => true,
            'filter' => $filterOut,
            'summary' => $summary,
            'sections' => $sections,
        ];
    }

    /**
     * Rincian kas per akun lawan, dikelompokkan per seksi arus kas.
     *
     * @param  int[]  $cashAccountIds
     * @param  array<string,mixed>  $filters
     * @return list<array<string,mixed>>
     */
    private function buildDirectSections(array $filters, array $cashAccountIds, string $start, string $end): array
    {
        // JE dalam periode yang menyentuh akun kas (dengan filter dimensi).
        $cashJes = $this->applyDimensionFilters($this->baseReportableCashLineQuery(), $filters)
            ->whereIn('jel.account_id', $cashAccountIds)
            ->whereDate('je.journal_date', '>=', $start)
            ->whereDate('je.journal_date', '<=', $end)
            ->selectRaw('je.id as je_id, SUM(jel.debit) as total_debit, SUM(jel.credit) as total_credit')
            ->groupBy('je.id')
            ->get();

        if ($cashJes->isEmpty()) {
            return [];
        }

        $jeIds = $cashJes->pluck('je_id')->all();

        // Baris non-kas dari JE tsb, per akun lawan + seksi.
        $contraRows = DB::connection('tenant')->table('journal_entry_lines as jel')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->whereIn('jel.journal_entry_id', $jeIds)
            ->whereNotIn('jel.account_id', $cashAccountIds)
            ->selectRaw("jel.journal_entry_id as je_id, jel.account_id, coa.account_code, coa.account_name, COALESCE(coa.cash_flow_section, 'unclassified') as section, SUM(jel.debit + jel.credit) as weight")
            ->groupBy('jel.journal_entry_id', 'jel.account_id', 'coa.account_code', 'coa.account_name', 'section')
            ->get();

        $contraByJe = [];
        foreach ($contraRows as $row) {
            $contraByJe[(int) $row->je_id][] = $row;
        }

        // Akumulasi kas per (seksi|akun lawan).
        $lines = [];
        foreach ($cashJes as $je) {
            $cashIn = (float) $je->total_debit;
            $cashOut = (float) $je->total_credit;
            $contras = $contraByJe[(int) $je->je_id] ?? [];

            if ($contras === []) {
                // Transfer antar kas — belum terklasifikasi.
                $this->accumulate($lines, 'unclassified', null, 'Transfer Kas', null, $cashIn, $cashOut);

                continue;
            }

            $totalWeight = (float) array_sum(array_map(fn ($c) => (float) $c->weight, $contras));

            foreach ($contras as $c) {
                $proportion = $totalWeight > 0 ? ((float) $c->weight) / $totalWeight : 1.0 / count($contras);
                $this->accumulate(
                    $lines,
                    (string) $c->section,
                    (int) $c->account_id,
                    (string) $c->account_name,
                    (string) $c->account_code,
                    $cashIn * $proportion,
                    $cashOut * $proportion,
                );
            }
        }

        return $this->groupIntoSections($lines);
    }

    /**
     * @param  array<string,array<string,mixed>>  $lines
     */
    private function accumulate(array &$lines, string $section, ?int $accountId, string $accountName, ?string $accountCode, float $cashIn, float $cashOut): void
    {
        $key = $section.'|'.($accountId ?? 'x');
        $lines[$key] ??= [
            'section' => $section,
            'account_id' => $accountId,
            'account_code' => $accountCode,
            'account_name' => $accountName,
            'cash_in' => 0.0,
            'cash_out' => 0.0,
        ];
        $lines[$key]['cash_in'] += $cashIn;
        $lines[$key]['cash_out'] += $cashOut;
    }

    /**
     * @param  array<string,array<string,mixed>>  $lines
     * @return list<array<string,mixed>>
     */
    private function groupIntoSections(array $lines): array
    {
        $bySection = [];
        foreach ($lines as $line) {
            $net = (float) $line['cash_in'] - (float) $line['cash_out'];
            $bySection[$line['section']][] = [
                'account_id' => $line['account_id'],
                'account_code' => $line['account_code'],
                'account_name' => $line['account_name'],
                'cash_in' => round((float) $line['cash_in'], 2),
                'cash_out' => round((float) $line['cash_out'], 2),
                'net' => round($net, 2),
            ];
        }

        $out = [];
        foreach (array_keys(self::SECTION_LABELS) as $sectionKey) {
            $sectionLines = $bySection[$sectionKey] ?? [];
            if ($sectionLines === []) {
                continue;
            }

            usort($sectionLines, fn ($a, $b) => abs($b['net']) <=> abs($a['net']));
            $subtotal = array_sum(array_column($sectionLines, 'net'));

            $out[] = [
                'key' => $sectionKey,
                'label' => self::SECTION_LABELS[$sectionKey],
                'lines' => $sectionLines,
                'subtotal_net' => round($subtotal, 2),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,array{account_id:int,account_code:string,account_name:string,normal_balance:string,is_active:bool}>  $cashAccounts
     * @param  array<string,mixed>  $filters
     * @return array<string,float>
     */
    private function buildSummary(array $filters, array $cashAccounts, string $start, string $end): array
    {
        $accountIds = array_map(fn ($a) => (int) $a['account_id'], $cashAccounts);
        $normalById = [];
        foreach ($cashAccounts as $a) {
            $normalById[(int) $a['account_id']] = (string) $a['normal_balance'];
        }

        $opening = $this->cashTotals($filters, $accountIds, null, $start);
        $period = $this->cashTotals($filters, $accountIds, $start, $end);

        $openingBalance = 0.0;
        $cashIn = 0.0;
        $cashOut = 0.0;

        foreach ($accountIds as $id) {
            $nb = $normalById[$id];
            $openingBalance += $this->balanceCalculator->openingBalance(
                (float) ($opening[$id]['debit'] ?? 0),
                (float) ($opening[$id]['credit'] ?? 0),
                $nb,
            );
            $pDebit = (float) ($period[$id]['debit'] ?? 0);
            $pCredit = (float) ($period[$id]['credit'] ?? 0);
            if ($nb === 'debit') {
                $cashIn += $pDebit;
                $cashOut += $pCredit;
            } else {
                $cashIn += $pCredit;
                $cashOut += $pDebit;
            }
        }

        $net = $cashIn - $cashOut;

        return [
            'opening_cash_balance' => round($openingBalance, 2),
            'cash_in' => round($cashIn, 2),
            'cash_out' => round($cashOut, 2),
            'net_cash_flow' => round($net, 2),
            'ending_cash_balance' => round($openingBalance + $net, 2),
        ];
    }

    /**
     * @param  int[]  $accountIds
     * @param  array<string,mixed>  $filters
     * @return array<int,array{debit:float,credit:float}>
     */
    private function cashTotals(array $filters, array $accountIds, ?string $start, string $end): array
    {
        $q = $this->applyDimensionFilters($this->baseReportableCashLineQuery(), $filters)
            ->whereIn('jel.account_id', $accountIds);

        if ($start === null) {
            $q->whereDate('je.journal_date', '<', $end);
        } else {
            $q->whereDate('je.journal_date', '>=', $start)->whereDate('je.journal_date', '<=', $end);
        }

        $rows = $q->select(['jel.account_id', 'jel.debit', 'jel.credit'])->get();

        $map = [];
        foreach ($rows as $r) {
            $id = (int) $r->account_id;
            $map[$id] ??= ['debit' => 0.0, 'credit' => 0.0];
            $map[$id]['debit'] += (float) ($r->debit ?? 0);
            $map[$id]['credit'] += (float) ($r->credit ?? 0);
        }

        return $map;
    }

    /**
     * @return array<int,array{account_id:int,account_code:string,account_name:string,normal_balance:string,is_active:bool}>
     */
    private function getCashAccounts(): array
    {
        return ChartOfAccount::query()
            ->select(['id', 'account_code', 'account_name', 'normal_balance', 'is_active'])
            ->where('is_cash_bank', '=', 1)
            ->orderBy('account_code')
            ->get()
            ->map(fn ($a) => [
                'account_id' => (int) $a->id,
                'account_code' => (string) $a->account_code,
                'account_name' => (string) $a->account_name,
                'normal_balance' => (string) $a->normal_balance,
                'is_active' => (bool) $a->is_active,
            ])->all();
    }

    /**
     * @return array<string,float>
     */
    private function emptySummary(): array
    {
        return [
            'opening_cash_balance' => 0.0,
            'cash_in' => 0.0,
            'cash_out' => 0.0,
            'net_cash_flow' => 0.0,
            'ending_cash_balance' => 0.0,
        ];
    }

    private function baseReportableCashLineQuery(): Builder
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
