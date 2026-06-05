<?php

namespace App\Services\Reports;

use App\Data\Reports\LedgerFilter;
use App\Data\Reports\ProfitLossFilter;
use App\Models\Tenant\ChartOfAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProfitLossService
{
    public function __construct(
        private readonly LedgerBalanceCalculator $balanceCalculator,
        private readonly LedgerFilterValidator $filterValidator,
        private readonly ?ReportVisibilityService $visibilityService = null,
    ) {
    }

    public function getProfitLoss(ProfitLossFilter $filter): array
    {
        $validation = $this->filterValidator->validate(new LedgerFilter(
            startDate: $filter->startDate,
            endDate: $filter->endDate,
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

        $period = $this->getPeriodTotalsByAccount($filter);
        $sections = $this->buildSections($filter, $period);

        $totalRevenue = (float) ($sections['__totals']['revenue'] ?? 0);
        $totalExpense = (float) ($sections['__totals']['expense'] ?? 0);

        $net = $totalRevenue - $totalExpense;
        $netProfit = $net > 0 ? $net : 0.0;
        $netLoss = $net < 0 ? abs($net) : 0.0;

        unset($sections['__totals']);

        return [
            'valid' => true,
            'filter' => $filter->toArray(),
            'sections' => array_values($sections),
            'totals' => [
                'total_revenue' => $totalRevenue,
                'total_expense' => $totalExpense,
                'net_profit' => $netProfit,
                'net_loss' => $netLoss,
                'net_profit_or_loss' => $net,
            ],
        ];
    }

    /**
     * @return array<int,array{account:array,debit:float,credit:float,amount:float}>
     */
    public function getPeriodTotalsByAccount(ProfitLossFilter $filter): array
    {
        $query = $this->applyDimensionFilters($this->baseReportableJournalLineQuery(), $filter)
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->whereIn('coa.account_type', ['revenue', 'expense'])
            ->whereDate('je.journal_date', '>=', (string) $filter->startDate)
            ->whereDate('je.journal_date', '<=', (string) $filter->endDate)
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

    /**
     * @param array<int,array{account:array,debit:float,credit:float,amount:float}> $period
     * @return array<string,array>
     */
    public function buildSections(ProfitLossFilter $filter, array $period): array
    {
        $movementAccountIds = array_keys($period);

        $baseAccountsQuery = ChartOfAccount::query()
            ->select([
                'id',
                'account_code',
                'account_name',
                'account_type',
                'normal_balance',
                'is_active',
            ])
            ->whereIn('account_type', ['revenue', 'expense']);

        if (! $filter->includeInactiveAccounts) {
            $baseAccountsQuery->where(function ($q) use ($movementAccountIds) {
                $q->where('is_active', '=', 1);
                if ($movementAccountIds !== []) {
                    $q->orWhereIn('id', $movementAccountIds);
                }
            });
        }

        if (! $filter->includeZeroBalance) {
            // movement-only; include inactive movement too
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
            '__totals' => ['revenue' => 0.0, 'expense' => 0.0],
        ];

        $pushAccount = function (string $sectionKey, array $accRow) use (&$sections) {
            $sections[$sectionKey] ??= [
                'key' => $sectionKey,
                'label' => $sectionKey === 'revenue' ? 'Revenue' : ($sectionKey === 'expense' ? 'Expense' : ucfirst($sectionKey)),
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

            $periodRow = $period[$accountId] ?? null;
            $debit = (float) ($periodRow['debit'] ?? 0);
            $credit = (float) ($periodRow['credit'] ?? 0);

            $amount = $periodRow ? (float) $periodRow['amount'] : $this->calculateAccountAmount(
                accountType: $accountType,
                normalBalance: $normalBalance,
                debit: $debit,
                credit: $credit,
            );

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
                $pushAccount('accounts', $accRow);
            } else {
                $pushAccount($accountType, $accRow);
            }

            if ($accountType === 'revenue') {
                $sections['__totals']['revenue'] += $amount;
            } elseif ($accountType === 'expense') {
                $sections['__totals']['expense'] += $amount;
            }
        }

        // Ensure deterministic section ordering
        if ($filter->groupBy === 'none') {
            if (isset($sections['accounts'])) {
                $sections['accounts']['key'] = 'accounts';
                $sections['accounts']['label'] = 'Accounts';
            }
        } else {
            $sections = [
                '__totals' => $sections['__totals'],
                'revenue' => $sections['revenue'] ?? ['key' => 'revenue', 'label' => 'Revenue', 'accounts' => [], 'total' => 0.0],
                'expense' => $sections['expense'] ?? ['key' => 'expense', 'label' => 'Expense', 'accounts' => [], 'total' => 0.0],
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

    public function applyDimensionFilters(Builder $query, ProfitLossFilter $filter): Builder
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

