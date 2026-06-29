<?php

namespace App\Services\Reports;

use App\Data\Reports\AccountLedgerFilter;
use App\Data\Reports\AccountLedgerLineData;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccountLedgerDetailService
{
    private const MAX_LINES = 2000;

    public function __construct(
        private readonly LedgerBalanceCalculator $calculator,
        private readonly LedgerFilterValidator $filterValidator,
        private readonly ?ReportVisibilityService $visibilityService = null,
    ) {
    }

    /**
     * @return array{valid:bool,errors?:array,account?:array,filter?:array,opening_balance?:array,period_totals?:array,ending_balance?:float,lines?:array<int,array>}
     */
    public function getDetail(int $accountId, AccountLedgerFilter $filter): array
    {
        $account = $this->getAccount($accountId);
        if (! $account) {
            return [
                'valid' => false,
                'errors' => ['account' => ['Account not found.']],
                'status' => 404,
            ];
        }

        $ledgerFilter = new \App\Data\Reports\LedgerFilter(
            startDate: $filter->startDate,
            endDate: $filter->endDate,
            accountId: $accountId,
            departmentId: $filter->departmentId,
            projectId: $filter->projectId,
            includeOpeningBalance: $filter->includeOpeningBalance,
            includeZeroBalance: $filter->includeZeroBalance,
            sortBy: 'journal_date',
            sortDirection: $filter->sortDirection,
        );

        $validation = $this->filterValidator->validate($ledgerFilter);
        if (! $validation['valid']) {
            return [
                'valid' => false,
                'errors' => $validation['errors'],
                'status' => 422,
            ];
        }

        $normalBalance = (string) ($account->normal_balance ?? '');
        if (! in_array($normalBalance, ['debit', 'credit'], true)) {
            throw new InvalidArgumentException('Unknown normal_balance: '.$normalBalance);
        }

        $opening = $filter->includeOpeningBalance
            ? $this->getOpeningBalance($accountId, $filter, $normalBalance)
            : ['debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0];

        $period = $this->getPeriodTotals($accountId, $filter, $normalBalance);

        $ending = $this->calculator->endingBalance(
            (float) ($opening['balance'] ?? 0),
            (float) ($period['debit'] ?? 0),
            (float) ($period['credit'] ?? 0),
            $normalBalance
        );

        $lines = $this->getLines($accountId, $filter, (float) ($opening['balance'] ?? 0), $normalBalance);
        $totalLines = count($lines);
        $truncated = $totalLines > self::MAX_LINES;
        $visibleLines = $truncated ? array_slice($lines, 0, self::MAX_LINES) : $lines;

        return [
            'valid' => true,
            'account' => [
                'id' => (int) $account->id,
                'account_code' => (string) $account->account_code,
                'account_name' => (string) $account->account_name,
                'account_type' => (string) $account->account_type,
                'normal_balance' => $normalBalance,
                'is_active' => (bool) ($account->is_active ?? true),
            ],
            'filter' => $filter->toArray(),
            'opening_balance' => $opening,
            'period_totals' => $period,
            'ending_balance' => $ending,
            'lines' => array_map(fn (AccountLedgerLineData $l) => $l->toArray(), $visibleLines),
            'total_lines' => $totalLines,
            'truncated' => $truncated,
        ];
    }

    public function getAccount(int $accountId): ?object
    {
        return DB::connection('tenant')->table('chart_of_accounts')->where('id', '=', $accountId)->first([
            'id',
            'account_code',
            'account_name',
            'account_type',
            'normal_balance',
            'is_active',
        ]);
    }

    public function getOpeningBalance(int $accountId, AccountLedgerFilter $filter, string $normalBalance): array
    {
        if (! $filter->startDate) {
            return ['debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0];
        }

        $row = $this->applyFilters(
            $this->baseReportableLineQuery($filter),
            $filter,
            forOpening: true
        )
            ->where('jel.account_id', '=', $accountId)
            ->selectRaw('COALESCE(SUM(jel.debit),0) as debit_total, COALESCE(SUM(jel.credit),0) as credit_total')
            ->first();

        $debit = (float) ($row->debit_total ?? 0);
        $credit = (float) ($row->credit_total ?? 0);
        $balance = $this->calculator->openingBalance($debit, $credit, $normalBalance);

        return [
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $balance,
        ];
    }

    public function getPeriodTotals(int $accountId, AccountLedgerFilter $filter, string $normalBalance): array
    {
        $row = $this->applyFilters(
            $this->baseReportableLineQuery($filter),
            $filter,
            forOpening: false
        )
            ->where('jel.account_id', '=', $accountId)
            ->selectRaw('COALESCE(SUM(jel.debit),0) as debit_total, COALESCE(SUM(jel.credit),0) as credit_total')
            ->first();

        $debit = (float) ($row->debit_total ?? 0);
        $credit = (float) ($row->credit_total ?? 0);
        $movementBalance = $this->calculator->signedAmount($debit, $credit, $normalBalance);

        return [
            'debit' => $debit,
            'credit' => $credit,
            'movement_balance' => $movementBalance,
        ];
    }

    /**
     * @return array<int,AccountLedgerLineData>
     */
    public function getLines(int $accountId, AccountLedgerFilter $filter, float $openingBalance, string $normalBalance): array
    {
        $query = $this->applyFilters(
            $this->baseReportableLineQuery($filter),
            $filter,
            forOpening: false
        )
            ->where('jel.account_id', '=', $accountId)
            ->select($this->selectColumns($filter));

        $dir = strtolower($filter->sortDirection) === 'desc' ? 'desc' : 'asc';
        $query
            ->orderBy('je.journal_date', $dir)
            ->orderBy('je.journal_number', $dir)
            ->orderBy('jel.line_order', $dir)
            ->orderBy('jel.id', $dir);

        $rows = $query->get();

        $running = $openingBalance;
        $out = [];

        foreach ($rows as $row) {
            $debit = (float) ($row->debit ?? 0);
            $credit = (float) ($row->credit ?? 0);
            $running = $this->calculator->runningBalance($running, $debit, $credit, $normalBalance);

            if (! $filter->includeSourceInfo) {
                $row->source_type = null;
                $row->source_number = null;
                $row->source_module = null;
                $row->source_revision = null;
            }

            if (! $filter->includeDimensions) {
                $row->department_id = null;
                $row->department_name = null;
                $row->project_id = null;
                $row->project_name = null;
            }

            $out[] = AccountLedgerLineData::fromRow($row, $running);
        }

        if (! $filter->includeZeroBalance && $out === []) {
            // keep response export-ready: account/opening/ending still returned, but UI may choose to hide.
            return [];
        }

        return $out;
    }

    public function baseReportableLineQuery(AccountLedgerFilter $filter): Builder
    {
        $query = DB::connection('tenant')->table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', '=', 'posted')
            ->where('je.is_obsolete', '=', 0);

        if ($filter->includeDimensions) {
            // safe in current repo (Phase 6A). If tables do not exist, joins will fail; filters are blocked by LedgerFilterValidator anyway.
            $query->leftJoin('departments as d', 'd.id', '=', 'jel.department_id')
                ->leftJoin('projects as p', 'p.id', '=', 'jel.project_id');
        }

        if ($this->visibilityService) {
            $query->whereIn('je.status', (array) config('report_visibility.reportable_journal_statuses', ['posted']));
        }

        return $query;
    }

    public function applyFilters(Builder $query, AccountLedgerFilter $filter, bool $forOpening = false): Builder
    {
        if ($forOpening) {
            if ($filter->startDate) {
                $query->whereDate('je.journal_date', '<', $filter->startDate);
            }
        } else {
            if ($filter->startDate) {
                $query->whereDate('je.journal_date', '>=', $filter->startDate);
            }
            if ($filter->endDate) {
                $query->whereDate('je.journal_date', '<=', $filter->endDate);
            }
        }

        if ($filter->departmentId !== null) {
            $query->where('jel.department_id', '=', $filter->departmentId);
        }

        if ($filter->projectId !== null) {
            $query->where('jel.project_id', '=', $filter->projectId);
        }

        return $query;
    }

    private function selectColumns(AccountLedgerFilter $filter): array
    {
        $cols = [
            'je.id as journal_entry_id',
            'jel.id as journal_entry_line_id',
            'je.journal_number',
            'je.journal_date',
            'je.description as journal_description',
            'jel.description as line_description',
            'jel.account_id as account_id',
            'coa.account_code as account_code',
            'coa.account_name as account_name',
            'jel.debit',
            'jel.credit',
        ];

        if ($filter->includeSourceInfo) {
            $cols[] = 'je.source_type';
            $cols[] = 'je.source_number';
            $cols[] = 'je.source_module';
            $cols[] = 'je.source_revision';
        } else {
            $cols[] = DB::raw('NULL as source_type');
            $cols[] = DB::raw('NULL as source_number');
            $cols[] = DB::raw('NULL as source_module');
            $cols[] = DB::raw('NULL as source_revision');
        }

        if ($filter->includeDimensions) {
            $cols[] = 'jel.department_id';
            $cols[] = 'jel.project_id';
            $cols[] = 'd.name as department_name';
            $cols[] = 'p.name as project_name';
        } else {
            $cols[] = DB::raw('NULL as department_id');
            $cols[] = DB::raw('NULL as project_id');
            $cols[] = DB::raw('NULL as department_name');
            $cols[] = DB::raw('NULL as project_name');
        }

        return $cols;
    }
}

