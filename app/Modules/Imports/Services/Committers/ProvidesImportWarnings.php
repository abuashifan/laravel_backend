<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\Imports\Models\ImportBatch;

/**
 * Kanal peringatan opsional untuk sebuah profil impor.
 *
 * Sengaja dipisah dari `ImportProfileCommitter`, bukan ditambahkan ke sana:
 * delapan committer lain tidak punya peringatan apa pun, dan memaksa mereka
 * mengembalikan array kosong hanya menambah kebisingan.
 * `ImportBatchService` memanggil ini lewat `instanceof`.
 *
 * Bedanya dengan `validateRow()`: galat MENGGAGALKAN baris, peringatan tidak.
 * Baris yang berperingatan tetap `valid` dan tetap ter-commit. Pakai ini untuk
 * hal yang MUNGKIN salah tapi sah-sah saja untuk data warisan — bukan untuk
 * hal yang pasti salah.
 */
interface ProvidesImportWarnings
{
    /**
     * @param  array<string, string>  $normalized
     * @return array<string, list<string>> kosong berarti tidak ada peringatan
     */
    public function warnRow(ImportBatch $batch, array $normalized): array;
}
