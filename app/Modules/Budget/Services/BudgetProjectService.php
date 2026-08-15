<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Support\BudgetDirection;
use App\Modules\MasterData\Models\Project;

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
    public function __construct(private readonly BudgetAnalysisService $analysisService) {}

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
