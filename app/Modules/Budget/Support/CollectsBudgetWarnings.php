<?php

namespace App\Modules\Budget\Support;

use App\Modules\Budget\Services\BudgetWarningService;

/**
 * Gap B — mengumpulkan peringatan over-budget setelah dokumen non-jurnal
 * diposting. Isinya sama persis dengan `JournalEntryController::collectBudgetWarnings()`,
 * hanya diparameterkan supaya tidak ditulis ulang di setiap modul transaksi.
 *
 * Pemanggil bertanggung jawab menormalkan baris dokumennya sendiri jadi bentuk
 * `{account_id, department_id, project_id, amount}` — nama kolom akun berbeda
 * antar modul (`account_id` di Cash Payment, `revenue_account_id` di Sales
 * Invoice, `expense_account_id` di Purchase Order, `cogs_account_id` di stock
 * movement), jadi normalisasinya sengaja tidak digeneralisasi di sini.
 *
 * `amount` WAJIB sudah bertanda benar untuk arah baris itu (positif = "ini
 * menambah konsumsi anggaran"). Berbeda dari Journal, yang debit-minus-kredit
 * seragam ke semua baris — itu berlaku untuk Journal karena baris manualnya
 * bisa dua arah; modul di sini tahu persis satu baris itu pendapatan atau
 * beban, jadi tandanya diambil dari situ, bukan dihitung ulang dari debit/kredit.
 *
 * ## `$postHoc` — kenapa parameter ini ada
 *
 * `BudgetWarningService::check()` menghitung `new_total = actual + amountToPost`.
 * Itu benar untuk pemeriksaan PRA-posting ("kalau baris ini ditambahkan, apakah
 * akan melebihi anggaran?") — cocok untuk Purchase Order, yang TIDAK PERNAH
 * memposting jurnal, jadi `actual` yang dibaca dari ledger memang belum memuat
 * komitmen PO ini.
 *
 * Tapi empat integrasi lain (Cash Payment, Sales Invoice, Stock Movement, Fixed
 * Assets) memanggil pengecekan ini SETELAH dokumennya sudah diposting — jurnalnya
 * sudah ada di ledger saat `check()` berjalan, sehingga `actual` yang dibaca
 * SUDAH memuat transaksi yang sedang diperiksa. Menambahkan `amountToPost` lagi
 * di atasnya menghitung transaksi yang sama dua kali (`new_total` jadi persis
 * dua kali lipat yang sebenarnya untuk transaksi pertama pada kombinasi
 * dimensi itu). `postHoc: true` mengoreksi ini dengan mengirim `amountToPost: 0`
 * ke `check()` — `actual` yang dibaca sudah menjadi `new_total` yang benar.
 * `amount` di baris tetap dipakai sebagai gerbang (lewati baris nol/negatif),
 * hanya nilai yang dikirim ke `check()` yang berbeda.
 *
 * Catatan: `JournalEntryController::collectBudgetWarnings()` — pola asal yang
 * ditiru integrasi ini — punya karakteristik post-hoc yang SAMA tapi TIDAK
 * dikoreksi (`amountToPost` di sana selalu nilai transaksi, bukan 0). Itu bukan
 * disengaja sebagai desain; tidak ada test yang menegaskan nilai `new_total`
 * presisi lewat jalur HTTP-nya sehingga belum pernah ketahuan. Tidak diperbaiki
 * di sini karena Journal eksplisit jadi rujukan yang tidak diminta diubah —
 * dicatat sebagai temuan terpisah.
 */
trait CollectsBudgetWarnings
{
    /**
     * @param  iterable<array{account_id:int|null,department_id:int|null,project_id:int|null,amount:float}>  $lines
     * @return list<array<string,mixed>>
     */
    private function collectBudgetWarningsFor(
        BudgetWarningService $budgetWarning,
        int $companyId,
        iterable $lines,
        string $period,
        bool $postHoc = false,
    ): array {
        if ($period === '') {
            return [];
        }

        $warnings = [];

        foreach ($lines as $line) {
            $amount = (float) ($line['amount'] ?? 0);
            $accountId = $line['account_id'] ?? null;

            if ($amount <= 0 || ! $accountId) {
                continue;
            }

            $warning = $budgetWarning->check(
                companyId: $companyId,
                accountId: (int) $accountId,
                departmentId: isset($line['department_id']) ? (int) $line['department_id'] : null,
                projectId: isset($line['project_id']) ? (int) $line['project_id'] : null,
                period: $period,
                amountToPost: $postHoc ? 0.0 : $amount,
            );

            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }
}
