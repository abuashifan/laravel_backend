<?php

namespace App\Services\Reports;

use App\Data\Reports\LedgerAccountSummaryData;
use App\Data\Reports\LedgerFilter;
use App\Data\Reports\LedgerLineData;
use App\Models\Tenant\ChartOfAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class GeneralLedgerQueryService
{
    public function __construct(
        private readonly LedgerBalanceCalculator $calculator,
        private readonly LedgerFilterValidator $validator,
        private readonly ?ReportVisibilityService $visibilityService = null,
    ) {
    }

    public function getLedger(LedgerFilter $filter): array
    {
        $validation = $this->validator->validate($filter);
        if (! $validation['valid']) {
            return [
                'valid' => false,
                'errors' => $validation['errors'],
            ];
        }

        if ($filter->accountId) {
            return $this->getAccountLedger($filter->accountId, $filter);
        }

        $accounts = ChartOfAccount::query()
            ->orderBy('account_code')
            ->get(['id', 'account_code', 'account_name', 'account_type', 'normal_balance'])
            ->values();

        $rows = [];

        foreach ($accounts as $acc) {
            $accountId = (int) $acc->id;

            $opening = $filter->includeOpeningBalance
                ? $this->getOpeningBalance($accountId, $filter)
                : ['debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0];

            $period = $this->getPeriodMovements($accountId, $filter);

            $ending = $this->calculator->endingBalance(
                (float) ($opening['balance'] ?? 0),
                (float) ($period['debit'] ?? 0),
                (float) ($period['credit'] ?? 0),
                (string) $acc->normal_balance
            );

            if (! $filter->includeZeroBalance && abs($ending) < 0.0000001 && abs((float) ($period['debit'] ?? 0)) < 0.0000001 && abs((float) ($period['credit'] ?? 0)) < 0.0000001) {
                continue;
            }

            $rows[] = (new LedgerAccountSummaryData(
                account: [
                    'id' => $accountId,
                    'account_code' => (string) $acc->account_code,
                    'account_name' => (string) $acc->account_name,
                    'account_type' => (string) $acc->account_type,
                    'normal_balance' => (string) $acc->normal_balance,
                ],
                openingBalance: $opening,
                periodTotals: $period,
                endingBalance: $ending,
            ))->toArray();
        }

        return [
            'valid' => true,
            'filter' => $filter->toArray(),
            'accounts' => $rows,
        ];
    }

    public function getAccountLedger(int $accountId, LedgerFilter $filter): array
    {
        $accRow = DB::connection('tenant')->table('chart_of_accounts')
            ->where('id', '=', $accountId)
            ->first(['id', 'account_code', 'account_name', 'account_type', 'normal_balance']);

        if (! $accRow) {
            // Fallback: resolve via joined reportable data (if any exists for the account).
            $accRow = $this->baseReportableJournalQuery()
                ->where('jel.account_id', '=', $accountId)
                ->select(['coa.id', 'coa.account_code', 'coa.account_name', 'coa.account_type', 'coa.normal_balance'])
                ->first();
        }

        if (! $accRow) {
            // If account truly doesn't exist, return an empty ledger (Phase 7A query foundation is read-only).
            return [
                'valid' => true,
                'account' => [
                    'id' => $accountId,
                    'account_code' => null,
                    'account_name' => null,
                    'account_type' => null,
                    'normal_balance' => null,
                ],
                'filter' => $filter->toArray(),
                'opening_balance' => ['debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0],
                'period_totals' => ['debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0],
                'ending_balance' => 0.0,
                'lines' => [],
            ];
        }

        $account = new ChartOfAccount();
        $account->id = (int) $accRow->id;
        $account->account_code = (string) $accRow->account_code;
        $account->account_name = (string) $accRow->account_name;
        $account->account_type = (string) $accRow->account_type;
        $account->normal_balance = (string) $accRow->normal_balance;

        $opening = $filter->includeOpeningBalance
            ? $this->getOpeningBalance($accountId, $filter)
            : ['debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0];

        $period = $this->getPeriodMovements($accountId, $filter);

        $ending = $this->calculator->endingBalance(
            (float) ($opening['balance'] ?? 0),
            (float) ($period['debit'] ?? 0),
            (float) ($period['credit'] ?? 0),
            (string) $account->normal_balance
        );

        $lines = $this->getLedgerLines($accountId, $filter, (float) ($opening['balance'] ?? 0), (string) $account->normal_balance);

        return [
            'valid' => true,
            'account' => [
                'id' => (int) $account->id,
                'account_code' => (string) $account->account_code,
                'account_name' => (string) $account->account_name,
                'account_type' => (string) $account->account_type,
                'normal_balance' => (string) $account->normal_balance,
            ],
            'filter' => $filter->toArray(),
            'opening_balance' => $opening,
            'period_totals' => $period,
            'ending_balance' => $ending,
            'lines' => array_map(fn (LedgerLineData $l) => $l->toArray(), $lines),
        ];
    }

    public function getOpeningBalance(int $accountId, LedgerFilter $filter): array
    {
        if (! $filter->startDate) {
            return ['debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0];
        }

        $account = ChartOfAccount::query()->findOrFail($accountId, ['id', 'normal_balance']);

        $row = $this->applyFilters(
            $this->baseReportableJournalQuery(),
            $filter,
            forOpening: true
        )
            ->where('jel.account_id', '=', $accountId)
            ->selectRaw('COALESCE(SUM(jel.debit),0) as debit_total, COALESCE(SUM(jel.credit),0) as credit_total')
            ->first();

        $debit = (float) ($row->debit_total ?? 0);
        $credit = (float) ($row->credit_total ?? 0);
        $balance = $this->calculator->openingBalance($debit, $credit, (string) $account->normal_balance);

        return [
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $balance,
        ];
    }

    public function getPeriodMovements(int $accountId, LedgerFilter $filter): array
    {
        $account = ChartOfAccount::query()->findOrFail($accountId, ['id', 'normal_balance']);

        $row = $this->applyFilters(
            $this->baseReportableJournalQuery(),
            $filter,
            forOpening: false
        )
            ->where('jel.account_id', '=', $accountId)
            ->selectRaw('COALESCE(SUM(jel.debit),0) as debit_total, COALESCE(SUM(jel.credit),0) as credit_total')
            ->first();

        $debit = (float) ($row->debit_total ?? 0);
        $credit = (float) ($row->credit_total ?? 0);
        $balance = $this->calculator->signedAmount($debit, $credit, (string) $account->normal_balance);

        return [
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $balance,
        ];
    }

    /**
     * @return array<int,LedgerLineData>
     */
    public function getLedgerLines(int $accountId, LedgerFilter $filter, float $openingBalance, string $normalBalance): array
    {
        $query = $this->applyFilters(
            $this->baseReportableJournalQuery(),
            $filter,
            forOpening: false
        )
            ->where('jel.account_id', '=', $accountId)
            ->select([
                'je.id as journal_entry_id',
                'je.journal_number',
                'je.journal_date',
                'je.description as journal_description',
                'jel.id as journal_entry_line_id',
                'jel.description as line_description',
                'jel.debit',
                'jel.credit',
                'jel.department_id',
                'jel.project_id',
                'd.name as department_name',
                'p.name as project_name',
                'je.source_type',
                'je.source_number',
                'je.source_module',
            ]);

        $sortBy = $filter->sortBy ?: 'journal_date';
        $dir = strtolower($filter->sortDirection) === 'desc' ? 'desc' : 'asc';

        if ($sortBy === 'journal_number') {
            $query->orderBy('je.journal_number', $dir);
        } elseif ($sortBy === 'account_code') {
            $query->orderBy('coa.account_code', $dir)->orderBy('je.journal_date', $dir)->orderBy('jel.id', $dir);
        } else {
            $query->orderBy('je.journal_date', $dir)->orderBy('je.id', $dir)->orderBy('jel.id', $dir);
        }

        $rows = $query->get();

        $running = $openingBalance;
        $out = [];

        foreach ($rows as $r) {
            $debit = (float) $r->debit;
            $credit = (float) $r->credit;
            $running = $this->calculator->runningBalance($running, $debit, $credit, $normalBalance);

            $out[] = new LedgerLineData(
                journalEntryId: (int) $r->journal_entry_id,
                journalNumber: (string) $r->journal_number,
                journalDate: (string) $r->journal_date,
                description: (string) ($r->line_description ?? $r->journal_description),
                accountId: $accountId,
                debit: $debit,
                credit: $credit,
                runningBalance: $running,
                departmentId: $r->department_id ? (int) $r->department_id : null,
                departmentName: $r->department_name ? (string) $r->department_name : null,
                projectId: $r->project_id ? (int) $r->project_id : null,
                projectName: $r->project_name ? (string) $r->project_name : null,
                sourceType: $r->source_type ? (string) $r->source_type : null,
                sourceNumber: $r->source_number ? (string) $r->source_number : null,
                sourceModule: $r->source_module ? (string) $r->source_module : null,
            );
        }

        return $out;
    }

    public function baseReportableJournalQuery(): Builder
    {
        $query = DB::connection('tenant')->table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->leftJoin('departments as d', 'd.id', '=', 'jel.department_id')
            ->leftJoin('projects as p', 'p.id', '=', 'jel.project_id')
            ->where('je.status', '=', 'posted')
            ->where('je.is_obsolete', '=', 0);

        if ($this->visibilityService) {
            // extra safety: ensure posted journals are reportable according to config
            $query->whereIn('je.status', (array) config('report_visibility.reportable_journal_statuses', ['posted']));
        }

        return $query;
    }

    public function applyFilters(Builder $query, LedgerFilter $filter, bool $forOpening = false): Builder
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
}
