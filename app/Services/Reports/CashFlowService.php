<?php

namespace App\Services\Reports;

use App\Data\Reports\CashFlowFilter;
use App\Data\Reports\LedgerFilter;
use App\Models\Tenant\ChartOfAccount;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CashFlowService
{
    public function __construct(
        private readonly LedgerBalanceCalculator $balanceCalculator,
        private readonly LedgerFilterValidator $filterValidator,
        private readonly ?ReportVisibilityService $visibilityService = null,
    ) {
    }

    public function getCashFlow(CashFlowFilter $filter): array
    {
        $start = Carbon::parse($filter->startDate)->toDateString();
        $end = Carbon::parse($filter->endDate)->toDateString();

        $validation = $this->filterValidator->validate(new LedgerFilter(
            startDate: $start,
            endDate: $end,
            accountId: null,
            departmentId: $filter->departmentId,
            projectId: $filter->projectId,
            includeOpeningBalance: true,
            includeZeroBalance: true,
            sortBy: 'journal_date',
            sortDirection: 'asc',
        ));

        if (! $validation['valid']) {
            return [
                'valid' => false,
                'errors' => $validation['errors'],
            ];
        }

        $cashAccounts = $this->getCashAccounts();
        if ($cashAccounts === []) {
            return [
                'valid' => true,
                'filter' => $filter->toArray(),
                'summary' => [
                    'opening_cash_balance' => 0.0,
                    'cash_in' => 0.0,
                    'cash_out' => 0.0,
                    'net_cash_flow' => 0.0,
                    'ending_cash_balance' => 0.0,
                ],
                'accounts' => [],
                'notes' => ['no_cash_accounts' => true],
            ];
        }

        $opening = $this->getOpeningTotalsByCashAccount($filter, $cashAccounts, $start);
        $period = $this->getPeriodCashMovementsByAccount($filter, $cashAccounts, $start, $end);

        $accountRows = $this->buildAccountRows($filter, $cashAccounts, $opening, $period);

        $summary = [
            'opening_cash_balance' => 0.0,
            'cash_in' => 0.0,
            'cash_out' => 0.0,
            'net_cash_flow' => 0.0,
            'ending_cash_balance' => 0.0,
        ];

        foreach ($accountRows as $r) {
            $summary['opening_cash_balance'] += (float) $r['opening_balance'];
            $summary['cash_in'] += (float) $r['cash_in'];
            $summary['cash_out'] += (float) $r['cash_out'];
            $summary['net_cash_flow'] += (float) $r['net_cash_flow'];
            $summary['ending_cash_balance'] += (float) $r['ending_balance'];
        }

        $cashAccountIds = array_column($cashAccounts, 'account_id');
        $sections = $this->getSectionedCashFlow($filter, $cashAccountIds, $start, $end);

        return [
            'valid' => true,
            'filter' => $filter->toArray(),
            'summary' => $summary,
            'accounts' => $filter->includeAccountBreakdown ? $accountRows : [],
            'sections' => $sections,
        ];
    }

    /**
     * @return array<int,array{account_id:int,account_code:string,account_name:string,normal_balance:string,is_active:bool}>
     */
    public function getCashAccounts(): array
    {
        $accounts = ChartOfAccount::query()
            ->select(['id', 'account_code', 'account_name', 'normal_balance', 'is_active'])
            ->where('is_cash_bank', '=', 1)
            ->orderBy('account_code', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return $accounts->map(fn ($a) => [
            'account_id' => (int) $a->id,
            'account_code' => (string) $a->account_code,
            'account_name' => (string) $a->account_name,
            'normal_balance' => (string) $a->normal_balance,
            'is_active' => (bool) $a->is_active,
        ])->all();
    }

    /**
     * Classify period cash flows into operating / investing / financing / unclassified
     * by looking at the cash_flow_section of contra (non-cash) accounts in each JE.
     * For compound JEs with mixed sections, cash is attributed proportionally by
     * contra-side amounts.
     *
     * @param  int[]  $cashAccountIds
     * @return array<string,array{cash_in:float,cash_out:float,net:float}>
     */
    public function getSectionedCashFlow(CashFlowFilter $filter, array $cashAccountIds, string $start, string $end): array
    {
        $empty = static fn () => ['cash_in' => 0.0, 'cash_out' => 0.0, 'net' => 0.0];
        $sections = [
            'operating' => $empty(),
            'investing' => $empty(),
            'financing' => $empty(),
            'unclassified' => $empty(),
        ];

        if (empty($cashAccountIds)) {
            return $sections;
        }

        // Step 1: all JEs in period that touch a cash account (with dimension filters applied)
        $cashJeQuery = $this->applyDimensionFilters($this->baseReportableCashLineQuery(), $filter)
            ->whereIn('jel.account_id', $cashAccountIds)
            ->whereDate('je.journal_date', '>=', $start)
            ->whereDate('je.journal_date', '<=', $end)
            ->selectRaw('je.id as je_id, SUM(jel.debit) as total_debit, SUM(jel.credit) as total_credit')
            ->groupBy('je.id')
            ->get();

        if ($cashJeQuery->isEmpty()) {
            return $sections;
        }

        $cashJeMap = $cashJeQuery->keyBy('je_id');
        $jeIds = $cashJeMap->keys()->all();

        // Step 2: non-cash lines from those JEs, grouped by JE + section
        $contraRows = DB::connection('tenant')
            ->table('journal_entry_lines as jel')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->whereIn('jel.journal_entry_id', $jeIds)
            ->whereNotIn('jel.account_id', $cashAccountIds)
            ->selectRaw("jel.journal_entry_id as je_id, COALESCE(coa.cash_flow_section, 'unclassified') as section, SUM(jel.debit + jel.credit) as weight")
            ->groupBy('jel.journal_entry_id', 'section')
            ->get();

        // Index by je_id
        $contraByJe = [];
        foreach ($contraRows as $row) {
            $contraByJe[(int) $row->je_id][] = [
                'section' => (string) $row->section,
                'weight' => (float) $row->weight,
            ];
        }

        // Step 3: proportionally attribute each JE's cash to its sections
        foreach ($cashJeMap as $jeId => $je) {
            $cashIn = (float) $je->total_debit;
            $cashOut = (float) $je->total_credit;

            $contras = $contraByJe[(int) $jeId] ?? [];

            if (empty($contras)) {
                // Cash-to-cash transfer — unclassified
                $sections['unclassified']['cash_in'] += $cashIn;
                $sections['unclassified']['cash_out'] += $cashOut;
                $sections['unclassified']['net'] += $cashIn - $cashOut;
                continue;
            }

            $totalWeight = (float) array_sum(array_column($contras, 'weight'));

            foreach ($contras as $c) {
                $section = $c['section'];
                $proportion = $totalWeight > 0 ? $c['weight'] / $totalWeight : 1.0 / count($contras);

                if (! isset($sections[$section])) {
                    $sections[$section] = $empty();
                }

                $sections[$section]['cash_in'] += $cashIn * $proportion;
                $sections[$section]['cash_out'] += $cashOut * $proportion;
                $sections[$section]['net'] += ($cashIn - $cashOut) * $proportion;
            }
        }

        return $sections;
    }

    /**
     * @param array<int,array{account_id:int,account_code:string,account_name:string,normal_balance:string,is_active:bool}> $cashAccounts
     * @return array<int,array{debit:float,credit:float}>
     */
    public function getOpeningTotalsByCashAccount(CashFlowFilter $filter, array $cashAccounts, string $startDate): array
    {
        $accountIds = array_map(fn ($a) => (int) $a['account_id'], $cashAccounts);

        $q = $this->applyDimensionFilters($this->baseReportableCashLineQuery(), $filter)
            ->whereIn('jel.account_id', $accountIds)
            ->whereDate('je.journal_date', '<', $startDate)
            ->select(['jel.account_id', 'jel.debit', 'jel.credit'])
            ->get();

        $map = [];
        foreach ($q as $r) {
            $accountId = (int) $r->account_id;
            $map[$accountId] ??= ['debit' => 0.0, 'credit' => 0.0];
            $map[$accountId]['debit'] += (float) ($r->debit ?? 0);
            $map[$accountId]['credit'] += (float) ($r->credit ?? 0);
        }

        return $map;
    }

    /**
     * @param array<int,array{account_id:int,account_code:string,account_name:string,normal_balance:string,is_active:bool}> $cashAccounts
     * @return array<int,array{debit:float,credit:float}>
     */
    public function getPeriodCashMovementsByAccount(CashFlowFilter $filter, array $cashAccounts, string $startDate, string $endDate): array
    {
        $accountIds = array_map(fn ($a) => (int) $a['account_id'], $cashAccounts);

        $rows = $this->applyDimensionFilters($this->baseReportableCashLineQuery(), $filter)
            ->whereIn('jel.account_id', $accountIds)
            ->whereDate('je.journal_date', '>=', $startDate)
            ->whereDate('je.journal_date', '<=', $endDate)
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

    /**
     * @param array<int,array{account_id:int,account_code:string,account_name:string,normal_balance:string,is_active:bool}> $cashAccounts
     * @param array<int,array{debit:float,credit:float}> $opening
     * @param array<int,array{debit:float,credit:float}> $period
     * @return array<int,array>
     */
    public function buildAccountRows(CashFlowFilter $filter, array $cashAccounts, array $opening, array $period): array
    {
        $rows = [];

        foreach ($cashAccounts as $acc) {
            $accountId = (int) $acc['account_id'];
            $normalBalance = (string) $acc['normal_balance'];
            if (! in_array($normalBalance, ['debit', 'credit'], true)) {
                throw new InvalidArgumentException('Unknown normal_balance: '.$normalBalance);
            }

            $openingDebit = (float) ($opening[$accountId]['debit'] ?? 0);
            $openingCredit = (float) ($opening[$accountId]['credit'] ?? 0);
            $openingBalance = $this->balanceCalculator->openingBalance($openingDebit, $openingCredit, $normalBalance);

            $periodDebit = (float) ($period[$accountId]['debit'] ?? 0);
            $periodCredit = (float) ($period[$accountId]['credit'] ?? 0);

            $movement = $this->calculateCashMovement($normalBalance, $periodDebit, $periodCredit);
            $net = (float) $movement['net_cash_flow'];
            $endingBalance = $openingBalance + $net;

            $rows[] = [
                'account_id' => $accountId,
                'account_code' => (string) $acc['account_code'],
                'account_name' => (string) $acc['account_name'],
                'normal_balance' => $normalBalance,
                'opening_balance' => $openingBalance,
                'cash_in' => (float) $movement['cash_in'],
                'cash_out' => (float) $movement['cash_out'],
                'net_cash_flow' => $net,
                'ending_balance' => $endingBalance,
                'is_active' => (bool) $acc['is_active'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{cash_in:float,cash_out:float,net_cash_flow:float}
     */
    public function calculateCashMovement(string $normalBalance, float $debit, float $credit): array
    {
        if ($normalBalance === 'debit') {
            return [
                'cash_in' => $debit,
                'cash_out' => $credit,
                'net_cash_flow' => $debit - $credit,
            ];
        }

        if ($normalBalance === 'credit') {
            return [
                'cash_in' => $credit,
                'cash_out' => $debit,
                'net_cash_flow' => $credit - $debit,
            ];
        }

        throw new InvalidArgumentException('Unknown normal_balance: '.$normalBalance);
    }

    public function baseReportableCashLineQuery(): Builder
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

    public function applyDimensionFilters(Builder $query, CashFlowFilter $filter): Builder
    {
        if ($filter->departmentId !== null) {
            $query->where('jel.department_id', '=', $filter->departmentId);
        }
        if ($filter->projectId !== null) {
            $query->where('jel.project_id', '=', $filter->projectId);
        }

        return $query;
    }
}

