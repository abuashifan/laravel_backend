<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Support\BudgetState;

/**
 * Laporan Budget vs Actual per akun.
 *
 * Sejak fase 2 ini hanya pembungkus tipis `BudgetAnalysisService` dengan preset
 * `group_by=[account]` + `mode=variance`. Logika lamanya DIBUANG, bukan
 * dibiarkan hidup berdampingan — dua jalur agregasi yang mirip tapi tidak sama
 * persis adalah akar empat cacat perilaku yang ditutup rencana ini.
 *
 * Bentuk balasan dipertahankan supaya `BudgetComparisonController` dan
 * frontend-nya tidak ikut berubah; `direction` dan `state` ditambahkan sebagai
 * field baru (aditif).
 */
class BudgetComparisonService
{
    public function __construct(private readonly BudgetAnalysisService $analysisService) {}

    public function compare(array $filters): array
    {
        $analysis = $this->analysisService->analyze([
            'budget_period_id' => $filters['budget_period_id'] ?? null,
            'group_by' => ['account'],
            'mode' => 'variance',
            'department_id' => $filters['department_id'] ?? null,
            'project_id' => $filters['project_id'] ?? null,
            // Nama filter lama: `period_from`/`period_to`.
            'date_from' => $filters['period_from'] ?? null,
            'date_to' => $filters['period_to'] ?? null,
        ]);

        $rows = array_map(fn (array $row) => [
            'account_id' => $row['account_id'],
            'account_code' => $row['account_code'] ?? null,
            'account_name' => $row['account_name'] ?? null,
            'budget_amount' => $row['budget_amount'],
            'actual_amount' => $row['actual_amount'],
            'variance' => $row['variance'],
            'variance_pct' => $row['variance_pct'],
            'over_budget' => $row['state'] === BudgetState::OVER_BUDGET,
            'direction' => $row['direction'],
            'state' => $row['state'],
        ], $analysis['rows']);

        return [
            'period' => [
                'budget_period_id' => $analysis['period']['budget_period_id'],
                'name' => $analysis['period']['name'],
            ],
            'rows' => $rows,
            'totals' => [
                'budget_amount' => $analysis['totals']['budget_amount'],
                'actual_amount' => $analysis['totals']['actual_amount'],
                'variance' => $analysis['totals']['variance'],
            ],
            'meta' => $analysis['meta'],
        ];
    }
}
