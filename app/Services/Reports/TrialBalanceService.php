<?php

namespace App\Services\Reports;

use App\Data\Reports\LedgerFilter;
use App\Data\Reports\TrialBalanceAccountData;
use App\Data\Reports\TrialBalanceFilter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TrialBalanceService
{
    public function __construct(
        private readonly TrialBalanceCalculator $calculator,
        private readonly LedgerFilterValidator $filterValidator,
        private readonly ?ReportVisibilityService $visibilityService = null,
    ) {
    }

    public function getTrialBalance(TrialBalanceFilter $filter): array
    {
        $validation = $this->filterValidator->validate(new LedgerFilter(
            startDate: $filter->startDate,
            endDate: $filter->endDate,
            accountId: null,
            departmentId: $filter->departmentId,
            projectId: $filter->projectId,
            includeOpeningBalance: true,
            includeZeroBalance: $filter->includeZeroBalance,
            sortBy: 'journal_date',
            sortDirection: 'asc',
        ));

        if (! $validation['valid']) {
            return [
                'valid' => false,
                'errors' => $validation['errors'],
            ];
        }

        $opening = $this->getOpeningTotalsByAccount($filter);
        $period = $this->getPeriodTotalsByAccount($filter);

        $rows = $this->buildAccountRows($filter, $opening, $period);

        $totals = $this->calculator->totals($rows);

        return [
            'valid' => true,
            'filter' => $filter->toArray(),
            'accounts' => $rows,
            'totals' => $totals,
        ];
    }

    public function getOpeningTotalsByAccount(TrialBalanceFilter $filter): array
    {
        if (! $filter->startDate) {
            return [];
        }

        $q = $this->applyDimensionFilters(
            $this->baseReportableJournalLineQuery(),
            $filter
        )->whereRaw('substr(je.journal_date, 1, 10) < ?', [$filter->startDate]);

        return $this->sumLinesByAccount($q);
    }

    public function getPeriodTotalsByAccount(TrialBalanceFilter $filter): array
    {
        $q = $this->applyDimensionFilters(
            $this->baseReportableJournalLineQuery(),
            $filter
        );

        if ($filter->startDate) {
            $q->whereRaw('substr(je.journal_date, 1, 10) >= ?', [$filter->startDate]);
        }
        if ($filter->endDate) {
            $q->whereRaw('substr(je.journal_date, 1, 10) <= ?', [$filter->endDate]);
        }

        return $this->sumLinesByAccount($q);
    }

    /**
     * @return array<int,array{debit:float,credit:float}>
     */
    private function sumLinesByAccount(Builder $query): array
    {
        $rows = $query->select(['jel.account_id', 'jel.debit', 'jel.credit'])->get();

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
     * @param array<int,array{debit:float,credit:float}> $opening
     * @param array<int,array{debit:float,credit:float}> $period
     * @return array<int,array>
     */
    public function buildAccountRows(TrialBalanceFilter $filter, array $opening, array $period): array
    {
        $accountIdsWithMovement = array_values(array_unique(array_merge(array_keys($opening), array_keys($period))));

        $query = DB::connection('tenant')->table('chart_of_accounts as coa')
            ->select([
                'coa.id',
                'coa.account_code',
                'coa.account_name',
                'coa.account_type',
                'coa.normal_balance',
                'coa.is_active',
            ]);

        if ($filter->accountType) {
            $query->where('coa.account_type', '=', (string) $filter->accountType);
        }

        if (! $filter->includeInactiveAccounts) {
            $query->where(function ($q) use ($accountIdsWithMovement) {
                $q->where('coa.is_active', '=', 1);
                if ($accountIdsWithMovement !== []) {
                    $q->orWhereIn('coa.id', $accountIdsWithMovement);
                }
            });
        }

        $sortBy = in_array($filter->sortBy, ['account_code', 'account_name', 'account_type'], true) ? $filter->sortBy : 'account_code';
        $dir = strtolower($filter->sortDirection) === 'desc' ? 'desc' : 'asc';

        $query->orderBy('coa.'.$sortBy, $dir)->orderBy('coa.id', 'asc');

        $accounts = $query->get();

        $rows = [];

        foreach ($accounts as $acc) {
            $accountId = (int) $acc->id;
            $normalBalance = (string) $acc->normal_balance;
            if (! in_array($normalBalance, ['debit', 'credit'], true)) {
                throw new InvalidArgumentException('Unknown normal_balance: '.$normalBalance);
            }

            $openingDebitRaw = (float) ($opening[$accountId]['debit'] ?? 0);
            $openingCreditRaw = (float) ($opening[$accountId]['credit'] ?? 0);
            $periodDebit = (float) ($period[$accountId]['debit'] ?? 0);
            $periodCredit = (float) ($period[$accountId]['credit'] ?? 0);

            $calc = $this->calculator->calculateEnding(
                openingDebit: $openingDebitRaw,
                openingCredit: $openingCreditRaw,
                periodDebit: $periodDebit,
                periodCredit: $periodCredit,
                normalBalance: $normalBalance
            );

            $openingDebit = (float) $calc['opening_debit'];
            $openingCredit = (float) $calc['opening_credit'];
            $endingDebit = (float) $calc['ending_debit'];
            $endingCredit = (float) $calc['ending_credit'];
            $endingBalance = (float) $calc['ending_balance'];

            $isAllZero = abs($openingDebit) < 0.0000001
                && abs($openingCredit) < 0.0000001
                && abs($periodDebit) < 0.0000001
                && abs($periodCredit) < 0.0000001
                && abs($endingDebit) < 0.0000001
                && abs($endingCredit) < 0.0000001;

            if (! $filter->includeZeroBalance && $isAllZero) {
                continue;
            }

            $rows[] = (new TrialBalanceAccountData(
                accountId: $accountId,
                accountCode: (string) $acc->account_code,
                accountName: (string) $acc->account_name,
                accountType: (string) $acc->account_type,
                normalBalance: $normalBalance,
                isActive: (bool) $acc->is_active,
                openingDebit: $openingDebit,
                openingCredit: $openingCredit,
                periodDebit: $periodDebit,
                periodCredit: $periodCredit,
                endingDebit: $endingDebit,
                endingCredit: $endingCredit,
                endingBalance: $endingBalance,
            ))->toArray();
        }

        return $rows;
    }

    public function baseReportableJournalLineQuery(): Builder
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

    public function applyDimensionFilters(Builder $query, TrialBalanceFilter $filter): Builder
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
