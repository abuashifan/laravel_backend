<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Support\BudgetDirection;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Reports\Services\ReportQueryService;
use App\Shared\Reports\Data\ReportDateRange;
use App\Shared\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cash Budget — **tanpa tabel dan tanpa cash ledger baru**.
 *
 * ```
 * Beginning Cash          saldo akun is_cash_bank pada period_from − 1 hari
 * + Budgeted Cash Inflow  Σ budget_lines direction='revenue'
 * − Budgeted Cash Outflow Σ budget_lines direction='expense'
 * = Budgeted Ending Cash
 * ```
 *
 * ⚠️ **Asumsi akrual.** Anggaran bersifat akrual, jadi angka ini mengabaikan
 * jeda AR/AP: pendapatan yang dianggarkan bulan Maret diperlakukan sebagai kas
 * masuk bulan Maret walaupun invoice-nya baru tertagih bulan Mei. Asumsi ini
 * dikembalikan di `meta.assumption` supaya UI **wajib** menampilkannya — ini
 * bukan proyeksi kas sungguhan. Penghalusan lewat `payment_terms` adalah FUTURE.
 */
class BudgetCashService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BudgetAnalysisService $analysisService,
        private readonly ReportQueryService $reportQueryService,
    ) {}

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function summary(array $params): array
    {
        $period = BudgetPeriod::query()
            ->forCompany($this->tenantContext->companyId())
            ->findOrFail((int) ($params['budget_period_id'] ?? 0));

        $beginningCash = $this->beginningCash($period);

        $inflow = $this->analysisService->analyze([
            'budget_period_id' => $period->id,
            'group_by' => ['account'],
            'direction' => BudgetDirection::REVENUE,
            'project_id' => $params['project_id'] ?? null,
            'department_id' => $params['department_id'] ?? null,
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
        ]);

        $outflow = $this->analysisService->analyze([
            'budget_period_id' => $period->id,
            'group_by' => ['account'],
            'direction' => BudgetDirection::EXPENSE,
            'project_id' => $params['project_id'] ?? null,
            'department_id' => $params['department_id'] ?? null,
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
        ]);

        $budgetedInflow = (float) $inflow['totals']['budget_amount'];
        $budgetedOutflow = (float) $outflow['totals']['budget_amount'];
        $actualInflow = (float) $inflow['totals']['actual_amount'];
        $actualOutflow = (float) $outflow['totals']['actual_amount'];

        return [
            'period' => $inflow['period'],
            'beginning_cash' => number_format($beginningCash, 2, '.', ''),
            'beginning_cash_source' => $period->beginning_cash_override !== null ? 'override' : 'ledger',
            'budgeted' => [
                'inflow' => number_format($budgetedInflow, 2, '.', ''),
                'outflow' => number_format($budgetedOutflow, 2, '.', ''),
                'net' => number_format($budgetedInflow - $budgetedOutflow, 2, '.', ''),
                'ending_cash' => number_format($beginningCash + $budgetedInflow - $budgetedOutflow, 2, '.', ''),
            ],
            'actual' => [
                'inflow' => number_format($actualInflow, 2, '.', ''),
                'outflow' => number_format($actualOutflow, 2, '.', ''),
                'net' => number_format($actualInflow - $actualOutflow, 2, '.', ''),
                'ending_cash' => number_format($beginningCash + $actualInflow - $actualOutflow, 2, '.', ''),
            ],
            // Pengelompokan arus kas memakai `cash_flow_section` yang SUDAH ada
            // di chart of accounts — tidak ada klasifikasi baru.
            'sections' => $this->bySection($inflow['rows'], $outflow['rows']),
            'inflow_rows' => $inflow['rows'],
            'outflow_rows' => $outflow['rows'],
            'meta' => $inflow['meta'] + [
                'assumption' => 'Anggaran bersifat akrual. Cash budget ini mengabaikan jeda penagihan '
                    .'(AR/AP): pendapatan yang dianggarkan bulan tertentu dianggap masuk sebagai kas di '
                    .'bulan yang sama. Ini bukan proyeksi kas berbasis termin pembayaran.',
            ],
        ];
    }

    /**
     * Saldo kas/bank pada `period_from − 1 hari`, dihitung dari ledger. Kalau
     * `beginning_cash_override` diisi, nilai itu yang dipakai.
     */
    private function beginningCash(BudgetPeriod $period): float
    {
        if ($period->beginning_cash_override !== null) {
            return (float) $period->beginning_cash_override;
        }

        $cashAccountIds = ChartOfAccount::query()->where('is_cash_bank', true)->pluck('id')->all();

        if ($cashAccountIds === []) {
            return 0.0;
        }

        $query = $this->reportQueryService->reportableJournalLinesQuery();

        $this->reportQueryService->applyDateRange(
            $query,
            new ReportDateRange(startDate: CarbonImmutable::parse($period->period_from)->toDateString()),
            opening: true,
        );

        return (float) ($query
            ->whereIn('jel.account_id', $cashAccountIds)
            ->value(DB::raw('COALESCE(SUM(jel.debit - jel.credit), 0)')) ?? 0);
    }

    /**
     * Kelompokkan per `cash_flow_section` (operating / investing / financing).
     *
     * @param  array<int,array<string,mixed>>  $inflowRows
     * @param  array<int,array<string,mixed>>  $outflowRows
     * @return array<int,array<string,mixed>>
     */
    private function bySection(array $inflowRows, array $outflowRows): array
    {
        $accountIds = array_values(array_unique(array_filter(array_merge(
            array_column($inflowRows, 'account_id'),
            array_column($outflowRows, 'account_id'),
        ))));

        $sections = $accountIds === []
            ? []
            : ChartOfAccount::query()->whereIn('id', $accountIds)->pluck('cash_flow_section', 'id')->all();

        $totals = [];

        foreach (['inflow' => $inflowRows, 'outflow' => $outflowRows] as $bucket => $rows) {
            foreach ($rows as $row) {
                $section = $sections[$row['account_id']] ?? 'operating';
                $totals[$section] ??= ['section' => $section, 'inflow' => 0.0, 'outflow' => 0.0, 'actual_inflow' => 0.0, 'actual_outflow' => 0.0];
                $totals[$section][$bucket] += (float) $row['budget_amount'];
                $totals[$section]['actual_'.$bucket] += (float) $row['actual_amount'];
            }
        }

        return array_values(array_map(fn (array $section) => [
            'section' => $section['section'],
            'budgeted_inflow' => number_format($section['inflow'], 2, '.', ''),
            'budgeted_outflow' => number_format($section['outflow'], 2, '.', ''),
            'budgeted_net' => number_format($section['inflow'] - $section['outflow'], 2, '.', ''),
            'actual_inflow' => number_format($section['actual_inflow'], 2, '.', ''),
            'actual_outflow' => number_format($section['actual_outflow'], 2, '.', ''),
            'actual_net' => number_format($section['actual_inflow'] - $section['actual_outflow'], 2, '.', ''),
        ], $totals));
    }
}
