<?php

namespace App\Services\Reports;

use App\Data\Reports\BalanceSheetFilter;
use App\Data\Reports\LedgerFilter;
use App\Models\Tenant\ChartOfAccount;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BalanceSheetService
{
    public function __construct(
        private readonly LedgerBalanceCalculator $balanceCalculator,
        private readonly LedgerFilterValidator $filterValidator,
        private readonly ?ReportVisibilityService $visibilityService = null,
    ) {
    }

    public function getBalanceSheet(BalanceSheetFilter $filter): array
    {
        $asOf = Carbon::parse($filter->asOfDate)->toDateString();

        $validation = $this->filterValidator->validate(new LedgerFilter(
            startDate: null,
            endDate: $asOf,
            accountId: null,
            departmentId: $filter->departmentId,
            projectId: $filter->projectId,
            includeOpeningBalance: false,
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

        $totalsByAccount = $this->getTotalsByAccount($filter, $asOf);
        $currentYearProfitLoss = $this->calculateCurrentYearProfitLoss($filter, $asOf);

        $sections = $this->buildSections($filter, $totalsByAccount, $currentYearProfitLoss);

        $totalAssets = (float) ($sections['__totals']['asset'] ?? 0);
        $totalLiabilities = (float) ($sections['__totals']['liability'] ?? 0);
        $totalEquity = (float) ($sections['__totals']['equity'] ?? 0);

        $totalLiabilitiesAndEquity = $totalLiabilities + $totalEquity;
        $difference = $totalAssets - $totalLiabilitiesAndEquity;
        $isBalanced = abs($difference) < 0.01;

        unset($sections['__totals']);

        return [
            'valid' => true,
            'filter' => $filter->toArray(),
            'sections' => array_values($sections),
            'totals' => [
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'total_equity' => $totalEquity,
                'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
                'current_year_profit_or_loss' => $currentYearProfitLoss,
                'difference' => $difference,
                'is_balanced' => $isBalanced,
            ],
        ];
    }

    /**
     * @return array<int,array{account:array,debit:float,credit:float,amount:float}>
     */
    public function getTotalsByAccount(BalanceSheetFilter $filter, string $asOfDate): array
    {
        $query = $this->applyDimensionFilters($this->baseReportableJournalLineQuery(), $filter)
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->whereIn('coa.account_type', ['asset', 'liability', 'equity'])
            ->whereDate('je.journal_date', '<=', $asOfDate)
            ->select([
                'coa.id as account_id',
                'coa.account_code',
                'coa.account_name',
                'coa.account_type',
                'coa.normal_balance',
                'coa.is_active',
                'jel.debit',
                'jel.credit',
            ]);

        $rows = $query->get();

        $map = [];
        foreach ($rows as $r) {
            $accountId = (int) $r->account_id;
            $map[$accountId] ??= [
                'account' => [
                    'account_id' => $accountId,
                    'account_code' => (string) $r->account_code,
                    'account_name' => (string) $r->account_name,
                    'account_type' => (string) $r->account_type,
                    'normal_balance' => (string) $r->normal_balance,
                    'is_active' => (bool) $r->is_active,
                ],
                'debit' => 0.0,
                'credit' => 0.0,
                'amount' => 0.0,
            ];

            $map[$accountId]['debit'] += (float) ($r->debit ?? 0);
            $map[$accountId]['credit'] += (float) ($r->credit ?? 0);
        }

        foreach ($map as $accountId => $row) {
            $acc = (array) $row['account'];
            $amount = $this->calculateAccountAmount(
                accountType: (string) $acc['account_type'],
                normalBalance: (string) $acc['normal_balance'],
                debit: (float) $row['debit'],
                credit: (float) $row['credit'],
            );

            $map[$accountId]['amount'] = $amount;
        }

        return $map;
    }

    public function calculateCurrentYearProfitLoss(BalanceSheetFilter $filter, string $asOfDate): float
    {
        $query = $this->applyDimensionFilters($this->baseReportableJournalLineQuery(), $filter)
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->whereIn('coa.account_type', ['revenue', 'expense'])
            ->whereDate('je.journal_date', '<=', $asOfDate)
            ->select([
                'coa.account_type',
                'coa.normal_balance',
                'jel.debit',
                'jel.credit',
            ]);

        $rows = $query->get();

        $totalRevenue = 0.0;
        $totalExpense = 0.0;

        foreach ($rows as $r) {
            $accountType = (string) $r->account_type;
            $normalBalance = (string) $r->normal_balance;
            $amount = $this->balanceCalculator->signedAmount((float) ($r->debit ?? 0), (float) ($r->credit ?? 0), $normalBalance);

            if ($accountType === 'revenue') {
                $totalRevenue += $amount;
            } elseif ($accountType === 'expense') {
                $totalExpense += $amount;
            }
        }

        return $totalRevenue - $totalExpense;
    }

    /**
     * @param array<int,array{account:array,debit:float,credit:float,amount:float}> $totalsByAccount
     * @return array<string,array>
     */
    public function buildSections(BalanceSheetFilter $filter, array $totalsByAccount, float $currentYearProfitLoss): array
    {
        $movementAccountIds = array_keys($totalsByAccount);

        $baseAccountsQuery = ChartOfAccount::query()
            ->select(['id', 'account_code', 'account_name', 'account_type', 'normal_balance', 'is_active'])
            ->whereIn('account_type', ['asset', 'liability', 'equity']);

        if (! $filter->includeInactiveAccounts) {
            $baseAccountsQuery->where(function ($q) use ($movementAccountIds) {
                $q->where('is_active', '=', 1);
                if ($movementAccountIds !== []) {
                    $q->orWhereIn('id', $movementAccountIds);
                }
            });
        }

        if (! $filter->includeZeroBalance) {
            $accounts = $movementAccountIds === []
                ? collect()
                : ChartOfAccount::query()
                    ->select(['id', 'account_code', 'account_name', 'account_type', 'normal_balance', 'is_active'])
                    ->whereIn('id', $movementAccountIds)
                    ->get();
        } else {
            $accounts = $baseAccountsQuery->orderBy('account_type', 'asc')
                ->orderBy('account_code', 'asc')
                ->orderBy('id', 'asc')
                ->get();
        }

        $sections = [
            '__totals' => ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0],
        ];

        $pushAccount = function (string $sectionKey, string $label, array $accRow) use (&$sections) {
            $sections[$sectionKey] ??= [
                'key' => $sectionKey,
                'label' => $label,
                'accounts' => [],
                'total' => 0.0,
            ];
            $sections[$sectionKey]['accounts'][] = $accRow;
            $sections[$sectionKey]['total'] += (float) ($accRow['amount'] ?? 0);
        };

        foreach ($accounts as $acc) {
            $accountId = (int) $acc->id;
            $accountType = (string) $acc->account_type;
            $normalBalance = (string) $acc->normal_balance;

            if (! in_array($normalBalance, ['debit', 'credit'], true)) {
                throw new InvalidArgumentException('Unknown normal_balance: '.$normalBalance);
            }

            $row = $totalsByAccount[$accountId] ?? null;
            $debit = (float) ($row['debit'] ?? 0);
            $credit = (float) ($row['credit'] ?? 0);
            $amount = $row ? (float) $row['amount'] : $this->calculateAccountAmount($accountType, $normalBalance, $debit, $credit);

            $accRow = [
                'account_id' => $accountId,
                'account_code' => (string) $acc->account_code,
                'account_name' => (string) $acc->account_name,
                'account_type' => $accountType,
                'normal_balance' => $normalBalance,
                'debit' => $debit,
                'credit' => $credit,
                'amount' => $amount,
                'is_active' => (bool) $acc->is_active,
            ];

            if (! $filter->includeZeroBalance) {
                $isZero = abs($debit) < 0.0000001 && abs($credit) < 0.0000001 && abs($amount) < 0.0000001;
                if ($isZero) {
                    continue;
                }
            }

            if ($filter->groupBy === 'none') {
                $pushAccount('accounts', 'Accounts', $accRow);
            } else {
                $label = $accountType === 'asset' ? 'Assets' : ($accountType === 'liability' ? 'Liabilities' : 'Equity');
                $pushAccount($accountType, $label, $accRow);
            }

            $sections['__totals'][$accountType] += $amount;
        }

        // Current year profit/loss line in equity section
        $plRow = [
            'account_id' => null,
            'account_code' => null,
            'account_name' => 'Current Year Profit / Loss',
            'account_type' => 'equity',
            'normal_balance' => 'credit',
            'debit' => 0.0,
            'credit' => 0.0,
            'amount' => $currentYearProfitLoss,
            'is_active' => true,
            'is_system_generated' => true,
        ];

        if ($filter->groupBy === 'none') {
            $pushAccount('accounts', 'Accounts', $plRow);
        } else {
            $pushAccount('equity', 'Equity', $plRow);
        }

        $sections['__totals']['equity'] += $currentYearProfitLoss;

        // Ensure deterministic section ordering
        if ($filter->groupBy === 'none') {
            if (isset($sections['accounts'])) {
                $sections['accounts']['key'] = 'accounts';
                $sections['accounts']['label'] = 'Accounts';
            }
        } else {
            $sections = [
                '__totals' => $sections['__totals'],
                'asset' => $sections['asset'] ?? ['key' => 'asset', 'label' => 'Assets', 'accounts' => [], 'total' => 0.0],
                'liability' => $sections['liability'] ?? ['key' => 'liability', 'label' => 'Liabilities', 'accounts' => [], 'total' => 0.0],
                'equity' => $sections['equity'] ?? ['key' => 'equity', 'label' => 'Equity', 'accounts' => [], 'total' => 0.0],
            ];
        }

        return $sections;
    }

    public function calculateAccountAmount(string $accountType, string $normalBalance, float $debit, float $credit): float
    {
        if (! in_array($normalBalance, ['debit', 'credit'], true)) {
            throw new InvalidArgumentException('Unknown normal_balance: '.$normalBalance);
        }

        return $this->balanceCalculator->signedAmount($debit, $credit, $normalBalance);
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

    public function applyDimensionFilters(Builder $query, BalanceSheetFilter $filter): Builder
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

