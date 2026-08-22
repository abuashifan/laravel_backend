<?php

namespace App\Modules\Imports\Services\Committers\Concerns;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;

/**
 * Kode duplikat di DALAM satu berkas tidak tertangkap oleh pengecekan
 * `external_ref` di `ImportBatchService` (itu memang sengaja mengizinkan Ref
 * yang sama untuk dokumen bermultibaris — lihat catatan di sana). Untuk
 * master data, satu kode HANYA boleh muncul sekali per berkas.
 *
 * Baris-baris SEBELUMNYA di batch yang sama sudah tersimpan sebagai
 * `ImportRow` di titik ini — `applyMapping()` menyimpannya satu per satu di
 * dalam loop yang sama, jadi baris ke-N selalu bisa melihat baris 1..N-1
 * lewat query biasa. Dibandingkan di PHP, bukan lewat query JSON mentah:
 * portabel lintas driver, dan batas 1.000 baris per berkas membuat
 * biayanya tetap kecil dalam ukuran mutlak.
 */
trait DetectsDuplicateCodesInBatch
{
    private function isCodeUsedElsewhereInBatch(ImportBatch $batch, string $field, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        $needle = mb_strtolower($code);

        return ImportRow::query()
            ->where('import_batch_id', $batch->id)
            ->pluck('normalized')
            ->contains(fn (?array $normalized): bool => mb_strtolower((string) ($normalized[$field] ?? '')) === $needle);
    }
}
