<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Support\BudgetDirection;
use App\Modules\MasterData\Models\Project;
use App\Modules\Reports\Services\ReportQueryService;
use App\Shared\Reports\Data\ReportDateRange;
use App\Shared\Reports\Data\ReportDimensionFilter;
use App\Shared\Tenant\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Project Budget, Profitability & Margin.
 *
 * `MasterData\Models\Project` sudah generic, jadi tidak ada kolom
 * spesifik-industri yang ditambahkan — "Class Meeting", "Renovasi Kantor",
 * "Campaign", dan "Custom Production" semuanya baris yang bentuknya sama.
 *
 * | Metrik | Rumus |
 * |---|---|
 * | Profit | `Revenue − Cost` |
 * | Margin % | `Profit / Revenue × 100`; **null bila Revenue = 0** |
 *
 * ⚠️ Akurasi Actual Revenue bergantung pada fase 4. Invoice yang di-post
 * **sebelum** perbaikan itu tidak membawa `project_id` dan tidak akan terhitung.
 * Keterbatasan itu dikembalikan di `meta.limitation` supaya UI menampilkannya
 * alih-alih diam-diam menyajikan angka yang kurang.
 */
class BudgetProjectService
{
    /** Sama dengan `AccountLedgerDetailService` — daftar transaksi laporan tidak dipaginasi, dipotong di sini. */
    private const MAX_LINES = 2000;

    public function __construct(
        private readonly BudgetAnalysisService $analysisService,
        private readonly TenantContext $tenantContext,
        private readonly ReportQueryService $reportQueryService,
    ) {}

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function summary(int $projectId, array $params): array
    {
        $project = Project::query()->findOrFail($projectId);

        $revenue = $this->analysisService->analyze([
            'budget_period_id' => $params['budget_period_id'] ?? null,
            'group_by' => ['account'],
            'project_id' => $projectId,
            'direction' => BudgetDirection::REVENUE,
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
        ]);

        $cost = $this->analysisService->analyze([
            'budget_period_id' => $params['budget_period_id'] ?? null,
            'group_by' => ['account'],
            'project_id' => $projectId,
            'direction' => BudgetDirection::EXPENSE,
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
        ]);

        $budgetRevenue = (float) $revenue['totals']['budget_amount'];
        $budgetCost = (float) $cost['totals']['budget_amount'];
        $actualRevenue = (float) $revenue['totals']['actual_amount'];
        $actualCost = (float) $cost['totals']['actual_amount'];

        return [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'status' => $project->status,
                'is_active' => (bool) $project->is_active,
            ],
            'period' => $revenue['period'],
            'budget' => $this->block($budgetRevenue, $budgetCost),
            'actual' => $this->block($actualRevenue, $actualCost),
            'variance' => [
                // Pendapatan: lebih tinggi = favorable. Biaya: lebih rendah = favorable.
                'revenue' => number_format($actualRevenue - $budgetRevenue, 2, '.', ''),
                'cost' => number_format($budgetCost - $actualCost, 2, '.', ''),
                'profit' => number_format(($actualRevenue - $actualCost) - ($budgetRevenue - $budgetCost), 2, '.', ''),
            ],
            // Utilization proyek dihitung dari sisi biaya — "berapa banyak pagu
            // biaya yang sudah terpakai", bukan dari pendapatan.
            'cost_utilization_pct' => $budgetCost > 0.0 ? round(($actualCost / $budgetCost) * 100, 2) : null,
            'revenue_rows' => $revenue['rows'],
            'cost_rows' => $cost['rows'],
            'meta' => $revenue['meta'] + [
                'limitation' => 'Actual Revenue hanya menghitung invoice yang barisnya membawa proyek. '
                    .'Invoice yang diposting sebelum dimensi proyek mengalir ke jurnal pendapatan tidak '
                    .'akan terhitung di sini.',
            ],
        ];
    }

    /**
     * Daftar jurnal yang menyusun Actual Revenue/Cost proyek ini — jawaban atas
     * "angka ini datang dari transaksi mana saja". Sumbernya sama persis dengan
     * yang dijumlahkan `BudgetActualService` (jurnal `posted`, tidak `obsolete`),
     * jadi total baris di sini selalu cocok dengan `actual.revenue − actual.cost`
     * pada `summary()` untuk filter yang sama.
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function transactions(int $projectId, array $params): array
    {
        $project = Project::query()->findOrFail($projectId);

        $period = BudgetPeriod::query()
            ->forCompany($this->tenantContext->companyId())
            ->findOrFail((int) ($params['budget_period_id'] ?? 0));

        [$dateFrom, $dateTo] = $this->resolveDateRange($period, $params);

        $query = $this->reportQueryService->reportableJournalLinesQuery();

        $this->reportQueryService->applyDateRange(
            $query,
            new ReportDateRange(startDate: $dateFrom, endDate: $dateTo),
        );

        $this->reportQueryService->applyDimensionFilter(
            $query,
            ReportDimensionFilter::fromArray(array_filter([
                'department_id' => isset($params['department_id']) ? (int) $params['department_id'] : null,
                'project_id' => $projectId,
            ], fn ($value) => $value !== null)),
        );

        $query->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->leftJoin('departments as d', 'd.id', '=', 'jel.department_id');

        if (! empty($params['account_id'])) {
            $query->where('jel.account_id', (int) $params['account_id']);
        }
        if (! empty($params['direction'])) {
            $query->where('coa.account_type', (string) $params['direction']);
        } else {
            // Hanya akun laba-rugi yang punya arah anggaran (BudgetDirection::fromAccountType).
            // Baris neraca (mis. proyek membayar uang muka lewat akun asset) bukan
            // bagian dari Revenue/Cost proyek dan akan membingungkan kalau ikut tampil.
            $query->whereIn('coa.account_type', BudgetDirection::all());
        }

        $query->orderBy('je.journal_date')
            ->orderBy('je.journal_number')
            ->orderBy('jel.line_order')
            ->orderBy('jel.id');

        $rows = $query->get([
            'je.id as journal_entry_id',
            'jel.id as journal_entry_line_id',
            'je.journal_number',
            'je.journal_date',
            'je.description as journal_description',
            'jel.description as line_description',
            'jel.account_id',
            'coa.account_code',
            'coa.account_name',
            'coa.account_type',
            'jel.department_id',
            'd.name as department_name',
            'jel.debit',
            'jel.credit',
            'je.source_type',
            'je.source_number',
            'je.source_module',
        ]);

        $totalLines = $rows->count();
        $truncated = $totalLines > self::MAX_LINES;
        $visibleRows = $truncated ? $rows->take(self::MAX_LINES) : $rows;

        $totalRevenue = 0.0;
        $totalCost = 0.0;

        $lines = $visibleRows->map(function ($row) use (&$totalRevenue, &$totalCost) {
            $direction = BudgetDirection::fromAccountType($row->account_type);
            $debit = (float) $row->debit;
            $credit = (float) $row->credit;
            $amount = $direction === BudgetDirection::REVENUE ? $credit - $debit : $debit - $credit;

            if ($direction === BudgetDirection::REVENUE) {
                $totalRevenue += $amount;
            } elseif ($direction === BudgetDirection::EXPENSE) {
                $totalCost += $amount;
            }

            return [
                'journal_entry_id' => (int) $row->journal_entry_id,
                'journal_entry_line_id' => (int) $row->journal_entry_line_id,
                'journal_number' => $row->journal_number,
                'journal_date' => $row->journal_date,
                'description' => $row->line_description ?: $row->journal_description,
                'account_id' => (int) $row->account_id,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'department_id' => $row->department_id !== null ? (int) $row->department_id : null,
                'department_name' => $row->department_name,
                'direction' => $direction,
                'debit' => number_format($debit, 2, '.', ''),
                'credit' => number_format($credit, 2, '.', ''),
                'amount' => number_format($amount, 2, '.', ''),
                'source_type' => $row->source_type,
                'source_number' => $row->source_number,
                'source_module' => $row->source_module,
            ];
        })->values()->all();

        return [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
            ],
            'period' => [
                'budget_period_id' => $period->id,
                'name' => $period->name,
            ],
            'filter' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'department_id' => isset($params['department_id']) ? (int) $params['department_id'] : null,
                'account_id' => isset($params['account_id']) ? (int) $params['account_id'] : null,
                'direction' => $params['direction'] ?? null,
            ],
            'lines' => $lines,
            'totals' => [
                'revenue' => number_format($totalRevenue, 2, '.', ''),
                'cost' => number_format($totalCost, 2, '.', ''),
                'net' => number_format($totalRevenue - $totalCost, 2, '.', ''),
            ],
            'total_lines' => $totalLines,
            'truncated' => $truncated,
        ];
    }

    /**
     * Rentang tanggal dipotong ke dalam periode anggaran — sama seperti
     * `BudgetAnalysisService::resolveDateRange()`, mesin lain di modul ini.
     * Rentang di luar periode tidak punya pembanding anggaran untuk dibandingkan.
     *
     * @param  array<string,mixed>  $params
     * @return array{0:string,1:string}
     */
    private function resolveDateRange(BudgetPeriod $period, array $params): array
    {
        $periodFrom = CarbonImmutable::parse($period->period_from);
        $periodTo = CarbonImmutable::parse($period->period_to);

        $from = ! empty($params['date_from']) ? CarbonImmutable::parse($params['date_from']) : $periodFrom;
        $to = ! empty($params['date_to']) ? CarbonImmutable::parse($params['date_to']) : $periodTo;

        $from = $from->lessThan($periodFrom) ? $periodFrom : $from;
        $to = $to->greaterThan($periodTo) ? $periodTo : $to;

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * @return array<string,string|float|null>
     */
    private function block(float $revenue, float $cost): array
    {
        $profit = $revenue - $cost;

        return [
            'revenue' => number_format($revenue, 2, '.', ''),
            'cost' => number_format($cost, 2, '.', ''),
            'profit' => number_format($profit, 2, '.', ''),
            // Tanpa pendapatan, margin tidak terdefinisi — jangan bagi nol dan
            // jangan kembalikan 0 (terbaca "impas", padahal artinya "tidak ada
            // pendapatan sama sekali").
            'margin_pct' => abs($revenue) > 0.0001 ? round(($profit / $revenue) * 100, 2) : null,
        ];
    }
}
