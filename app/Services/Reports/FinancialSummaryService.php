<?php

namespace App\Services\Reports;

use App\Data\Reports\BalanceSheetFilter;
use App\Data\Reports\CashFlowFilter;
use App\Data\Reports\FinancialSummaryFilter;
use App\Data\Reports\ProfitLossFilter;

class FinancialSummaryService
{
    public function __construct(
        private readonly ProfitLossService $profitLossService,
        private readonly BalanceSheetService $balanceSheetService,
        private readonly CashFlowService $cashFlowService,
    ) {
    }

    public function getSummary(FinancialSummaryFilter $filter): array
    {
        $pl = $this->profitLossService->getProfitLoss(new ProfitLossFilter(
            startDate: $filter->startDate,
            endDate: $filter->endDate,
            departmentId: $filter->departmentId,
            projectId: $filter->projectId,
            includeZeroBalance: false,
            includeInactiveAccounts: false,
            groupBy: 'account_type',
        ));

        if (! ($pl['valid'] ?? false)) {
            return $pl;
        }

        $bs = $this->balanceSheetService->getBalanceSheet(new BalanceSheetFilter(
            asOfDate: $filter->asOfDate,
            departmentId: $filter->departmentId,
            projectId: $filter->projectId,
            includeZeroBalance: false,
            includeInactiveAccounts: false,
            groupBy: 'account_type',
        ));

        if (! ($bs['valid'] ?? false)) {
            return $bs;
        }

        $cf = $this->cashFlowService->getCashFlow(new CashFlowFilter(
            startDate: $filter->startDate,
            endDate: $filter->endDate,
            departmentId: $filter->departmentId,
            projectId: $filter->projectId,
            includeAccountBreakdown: false,
        ));

        if (! ($cf['valid'] ?? false)) {
            return $cf;
        }

        return [
            'valid' => true,
            'filter' => $filter->toArray(),
            'profit_loss' => [
                'net_profit_or_loss' => (float) ($pl['totals']['net_profit_or_loss'] ?? 0),
            ],
            'balance_sheet' => [
                'total_assets' => (float) ($bs['totals']['total_assets'] ?? 0),
                'total_liabilities' => (float) ($bs['totals']['total_liabilities'] ?? 0),
                'total_equity' => (float) ($bs['totals']['total_equity'] ?? 0),
                'is_balanced' => (bool) ($bs['totals']['is_balanced'] ?? false),
                'current_year_profit_or_loss' => (float) ($bs['totals']['current_year_profit_or_loss'] ?? 0),
            ],
            'cash_flow' => [
                'opening_cash_balance' => (float) ($cf['summary']['opening_cash_balance'] ?? 0),
                'cash_in' => (float) ($cf['summary']['cash_in'] ?? 0),
                'cash_out' => (float) ($cf['summary']['cash_out'] ?? 0),
                'ending_cash_balance' => (float) ($cf['summary']['ending_cash_balance'] ?? 0),
            ],
        ];
    }
}

