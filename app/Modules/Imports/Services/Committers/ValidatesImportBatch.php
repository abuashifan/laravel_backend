<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\Imports\Models\ImportBatch;

/**
 * Aturan yang hanya bisa dinilai lintas baris (mis. debit = kredit per Ref
 * jurnal). Dijalankan sekali setelah semua baris batch tervalidasi, supaya
 * pratinjau sudah menolaknya -- bukan baru ketahuan saat commit.
 */
interface ValidatesImportBatch
{
    /**
     * @return array<int, array<string, list<string>>> galat tambahan, dikunci id ImportRow
     */
    public function validateBatch(ImportBatch $batch): array;
}
