<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\BudgetLine;
use App\Shared\Tenant\TenantContext;

/**
 * Tangga spesifisitas: baris jurnal (akun A, dept D, proyek P, bulan M)
 * mengonsumsi anggaran dari baris anggaran PALING SPESIFIK yang cocok.
 *
 * | Prioritas | account | department | project | period_month |
 * |-----------|---------|------------|---------|--------------|
 * | 1         | A       | D          | P       | M            |
 * | 2         | A       | D          | P       | NULL         |
 * | 3         | A       | D          | NULL    | M            |
 * | 4         | A       | D          | NULL    | NULL         |
 * | 5         | A       | NULL       | P       | M / NULL     |
 * | 6         | A       | NULL       | NULL    | M / NULL     |
 *
 * Dua cacat lama tertutup di sini:
 *
 * - **G7** — dulu baris jurnal ber-`project_id` HANYA dicocokkan ke baris anggaran
 *   berproyek sama, sehingga belanja proyek lolos dari anggaran departemen.
 *   Sekarang ia turun ke prioritas 3–4 bila tidak ada anggaran khusus proyek.
 * - **G8** — dulu `when($departmentId, …)` tidak memasang filter apa pun saat
 *   `$departmentId` null, lalu `first()` mengambil baris pertama yang kebetulan
 *   ketemu; baris jurnal tanpa departemen bisa mencocok anggaran departemen mana
 *   pun. Sekarang NULL dicocokkan dengan `whereNull` eksplisit.
 */
class BudgetMatchResolver
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  string  $periodMonth  'YYYY-MM'
     */
    public function resolve(int $accountId, ?int $departmentId, ?int $projectId, string $periodMonth): ?BudgetLine
    {
        foreach ($this->candidates($departmentId, $projectId, $periodMonth) as [$dept, $project, $month]) {
            $line = $this->baseQuery($accountId, $periodMonth)
                ->when($dept === null, fn ($q) => $q->whereNull('budget_lines.department_id'), fn ($q) => $q->where('budget_lines.department_id', $dept))
                ->when($project === null, fn ($q) => $q->whereNull('budget_lines.project_id'), fn ($q) => $q->where('budget_lines.project_id', $project))
                ->when($month === null, fn ($q) => $q->whereNull('budget_lines.period_month'), fn ($q) => $q->where('budget_lines.period_month', $month))
                ->first();

            if ($line !== null) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Kombinasi dimensi menurun dari paling spesifik ke paling umum: departemen
     * di lapisan terluar, lalu proyek, lalu bulan. Nilai yang memang sudah NULL
     * tidak diulang supaya tidak ada query kembar.
     *
     * @return array<int,array{0:?int,1:?int,2:?string}>
     */
    private function candidates(?int $departmentId, ?int $projectId, string $periodMonth): array
    {
        $departments = $departmentId === null ? [null] : [$departmentId, null];
        $projects = $projectId === null ? [null] : [$projectId, null];
        $months = [$periodMonth, null];

        $candidates = [];
        foreach ($departments as $dept) {
            foreach ($projects as $project) {
                foreach ($months as $month) {
                    $candidates[] = [$dept, $project, $month];
                }
            }
        }

        return $candidates;
    }

    /**
     * Hanya versi anggaran yang berlaku (approved + aktif) dan periodenya
     * mencakup bulan yang ditanyakan.
     */
    private function baseQuery(int $accountId, string $periodMonth)
    {
        $companyId = $this->tenantContext->companyId();
        $monthStart = $periodMonth.'-01';

        return BudgetLine::query()
            ->where('budget_lines.account_id', $accountId)
            ->whereHas('submission', function ($q) use ($companyId, $monthStart) {
                $q->where('company_id', $companyId)
                    ->where('status', 'approved')
                    ->where('is_active', true)
                    ->whereHas('period', fn ($p) => $p
                        ->where('period_from', '<=', $monthStart)
                        ->where('period_to', '>=', $monthStart));
            });
    }
}
