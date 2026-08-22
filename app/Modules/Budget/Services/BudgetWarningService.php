<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Support\BudgetState;

/**
 * Peringatan over-budget saat sebuah baris jurnal akan diposting.
 *
 * Service ini sengaja TIDAK punya logika pencocokan atau agregasi sendiri lagi.
 * Akar empat cacat lama adalah peringatan dan laporan perbandingan memakai
 * logika yang berbeda; keduanya kini memakai `BudgetMatchResolver`,
 * `BudgetAllocationResolver`, dan `BudgetActualService` yang sama — satu logika,
 * dua pemakai.
 *
 * Kontrak publiknya dipertahankan persis supaya `JournalEntryController` tidak
 * perlu ikut berubah.
 */
class BudgetWarningService
{
    public function __construct(
        private readonly BudgetMatchResolver $matchResolver,
        private readonly BudgetAllocationResolver $allocationResolver,
        private readonly BudgetActualService $actualService,
    ) {}

    /**
     * @param  string  $period  'YYYY-MM' bulan transaksi
     * @return array{account_id:int,budget_amount:float,actual_amount:float,new_total:float,overage:float,state:string,direction:string,matched_scope:string}|null
     */
    public function check(int $companyId, int $accountId, ?int $departmentId, ?int $projectId, string $period, float $amountToPost): ?array
    {
        $budgetLine = $this->matchResolver->resolve($accountId, $departmentId, $projectId, $period);

        if ($budgetLine === null) {
            return null;
        }

        $budgetPeriod = $budgetLine->submission?->period;

        if ($budgetPeriod === null) {
            return null;
        }

        [$dateFrom, $dateTo] = $this->allocationResolver->comparableRange($budgetLine, $budgetPeriod, $period);

        // Cakupan actual mengikuti dimensi BARIS ANGGARAN, bukan dimensi baris
        // jurnal: anggaran departemen tanpa proyek dikonsumsi oleh belanja proyek
        // mana pun di departemen itu (G7). Dimensi yang NULL di baris anggaran
        // berarti "tidak dibatasi", jadi filternya tidak dipasang.
        //
        // Catatan: anggaran tingkat perusahaan (departemen NULL) dan anggaran
        // departemen bisa sama-sama mengklaim belanja yang sama. Itu sifat
        // anggaran berjenjang, bukan bug pencocokan — penyelesaiannya (mis.
        // anggaran induk hanya menghitung sisa anak) di luar cakupan fase ini.
        $actual = $this->actualService->sumFor(
            accountId: $accountId,
            departmentId: $budgetLine->department_id,
            projectId: $budgetLine->project_id,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            matchNullDimensionsExactly: false,
        );

        $budgetAmount = (float) $budgetLine->amount;
        $newTotal = $actual + $amountToPost;

        if ($newTotal <= $budgetAmount) {
            return null;
        }

        return [
            'account_id' => $accountId,
            'budget_amount' => $budgetAmount,
            'actual_amount' => $actual,
            'new_total' => $newTotal,
            'overage' => $newTotal - $budgetAmount,
            // Untuk pendapatan, "melampaui" berarti target terlampaui — kabar
            // baik. UI wajib mewarnainya hijau, bukan merah.
            'state' => BudgetState::resolve($budgetAmount, $newTotal, $budgetLine->direction),
            'direction' => $budgetLine->direction,
            // Menjelaskan anggaran mana yang tersentuh. Tanpa ini user melihat
            // peringatan tanpa tahu ia berasal dari anggaran departemen, proyek,
            // atau perusahaan — pertanyaan pertama yang selalu muncul.
            'matched_scope' => $this->describeScope($budgetLine),
        ];
    }

    /**
     * Ringkasan dimensi baris anggaran yang cocok, mis.
     * `department:7|project:all|period:annual`.
     */
    private function describeScope(BudgetLine $line): string
    {
        return implode('|', [
            'department:'.($line->department_id ?? 'all'),
            'project:'.($line->project_id ?? 'all'),
            'period:'.($line->period_month ?? 'annual'),
        ]);
    }
}
