<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\BudgetPeriod;

/**
 * Konsolidasi anggaran per cost center / proyek.
 *
 * Sejak fase 2 ini pembungkus `BudgetAnalysisService` dengan preset
 * `group_by=[department,account]`, `[project,account]`, atau
 * `[department,project,account]`. Bentuk balasannya dipertahankan persis
 * (akun bersarang di dalam dimensi + `grand_total`) supaya
 * `BudgetConsolidationTable.tsx` tidak rusak.
 *
 * Perbedaan penting dari versi lama: departemen kini dibaca dari **dimensi
 * baris** (`budget_lines.department_id`), bukan dari header pengajuan. Baris
 * yang tidak menyebut departemen mewarisi departemen pemilik dokumen, jadi
 * angka untuk anggaran satu-departemen tetap sama.
 */
class BudgetConsolidationService
{
    public function __construct(private readonly BudgetAnalysisService $analysisService) {}

    public function query(BudgetPeriod $period, array $filters = []): array
    {
        $by = $filters['by'] ?? 'department';

        $groupBy = match ($by) {
            'project' => ['project', 'account'],
            'project_department' => ['department', 'project', 'account'],
            default => ['department', 'account'],
        };

        $analysis = $this->analysisService->analyze([
            'budget_period_id' => $period->id,
            'group_by' => $groupBy,
            'department_id' => $filters['department_id'] ?? null,
            'project_id' => $filters['project_id'] ?? null,
            'account_id' => $filters['account_id'] ?? null,
        ]);

        // Baris tanpa anggaran (hanya punya actual) tidak termasuk konsolidasi —
        // laporan ini menjawab "berapa yang dianggarkan", bukan realisasinya.
        $rows = array_values(array_filter(
            $analysis['rows'],
            fn (array $row) => (float) $row['budget_amount'] !== 0.0,
        ));

        $nested = match ($by) {
            'project' => $this->nestByProject($rows),
            'project_department' => $this->nestByDepartmentProject($rows),
            default => $this->nestByDepartment($rows),
        };

        $grandTotal = array_sum(array_map(fn (array $row) => (float) $row['budget_amount'], $rows));

        return [
            'budget_period' => ['id' => $period->id, 'name' => $period->name, 'fiscal_year' => $period->fiscal_year],
            'breakdown_by' => $by,
            'rows' => $nested,
            'grand_total' => number_format($grandTotal, 2, '.', ''),
        ];
    }

    private function nestByDepartment(array $rows): array
    {
        return $this->groupRows($rows, 'department_id', fn (array $group, $key) => [
            'department_id' => $key,
            'department_name' => $group[0]['department_name'] ?? null,
            'accounts' => $this->accountRows($group),
            'total_amount' => $this->sum($group),
        ]);
    }

    private function nestByProject(array $rows): array
    {
        return $this->groupRows($rows, 'project_id', fn (array $group, $key) => [
            'project_id' => $key,
            'project_name' => $group[0]['project_name'] ?? 'Tanpa Proyek',
            'accounts' => $this->accountRows($group),
            'total_amount' => $this->sum($group),
        ]);
    }

    private function nestByDepartmentProject(array $rows): array
    {
        return $this->groupRows($rows, 'department_id', fn (array $group, $key) => [
            'department_id' => $key,
            'department_name' => $group[0]['department_name'] ?? null,
            'projects' => $this->nestByProject($group),
            'total_amount' => $this->sum($group),
        ]);
    }

    /**
     * @param  callable(array<int,array<string,mixed>>, mixed):array<string,mixed>  $build
     */
    private function groupRows(array $rows, string $key, callable $build): array
    {
        $groups = [];
        foreach ($rows as $row) {
            // Kunci null dinormalkan ke string kosong supaya urutan array tetap
            // stabil; nilai aslinya diambil kembali dari baris pertama grup.
            $groups[(string) ($row[$key] ?? '')][] = $row;
        }

        $result = [];
        foreach ($groups as $group) {
            $result[] = $build($group, $group[0][$key] ?? null);
        }

        return $result;
    }

    private function accountRows(array $group): array
    {
        $byAccount = [];
        foreach ($group as $row) {
            $accountId = $row['account_id'];
            $byAccount[$accountId] ??= [
                'account_id' => $accountId,
                'account_name' => $row['account_name'] ?? null,
                'total' => 0.0,
            ];
            $byAccount[$accountId]['total'] += (float) $row['budget_amount'];
        }

        return array_values(array_map(fn (array $account) => [
            'account_id' => $account['account_id'],
            'account_name' => $account['account_name'],
            'total_amount' => number_format($account['total'], 2, '.', ''),
        ], $byAccount));
    }

    private function sum(array $group): string
    {
        return number_format(
            array_sum(array_map(fn (array $row) => (float) $row['budget_amount'], $group)),
            2,
            '.',
            ''
        );
    }
}
