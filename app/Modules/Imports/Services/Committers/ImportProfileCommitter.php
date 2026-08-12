<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\Imports\Models\ImportBatch;

/**
 * Satu implementasi per profil impor. Dua tanggung jawab terpisah:
 *
 * 1. `validateRow()` — aturan bisnis KHUSUS profil, di atas `required_fields`
 *    generik yang sudah dicek `ImportBatchService` (kode duplikat, akun
 *    induk, kategori/satuan tak dikenal, dst).
 * 2. `commit()` — menulis baris VALID lewat service dokumen yang sudah ada
 *    (Contact/Product/ChartOfAccountService). Menerima seluruh batch, bukan
 *    satu baris, karena urutan pemrosesan itu keputusan PROFIL: kebanyakan
 *    profil memprosesnya sesuai urutan baris, tapi COA wajib mengurutkan
 *    induk sebelum anak (lihat `ChartOfAccountImportCommitter`).
 *
 * Aturan tunggal yang tidak boleh dilanggar (rencana impor data §"Yang sudah
 * ada dan wajib dipakai ulang"): commit() WAJIB lewat service dokumen,
 * TIDAK BOLEH menulis ke Eloquent model secara langsung.
 */
interface ImportProfileCommitter
{
    /**
     * @param  array<string, string>  $normalized
     * @return array<string, list<string>> kosong berarti tidak ada galat tambahan
     */
    public function validateRow(ImportBatch $batch, array $normalized): array;

    /**
     * @return array<int, array{status: string, document_id: ?int, document_type: ?string, error: ?string}> dikunci id ImportRow
     */
    public function commit(ImportBatch $batch): array;
}
