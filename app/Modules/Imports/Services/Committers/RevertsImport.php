<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\Imports\Models\ImportBatch;

/**
 * Profil yang punya lawan untuk `commit()` — Fase 8.
 *
 * Sengaja opsional, bukan bagian `ImportProfileCommitter`. Tidak setiap profil
 * bisa dibatalkan dengan jujur: kontak, produk, dan akun yang sudah dipakai
 * transaksi tidak bisa "dihapus kembali" tanpa merusak dokumen yang menunjuk
 * mereka — membatalkannya bukan lagi soal impor, melainkan soal penghapusan
 * master data, dengan pertanyaannya sendiri.
 *
 * Yang mengimplementasikannya di gelombang pertama adalah dua profil saldo awal,
 * dan keduanya bisa karena alasan yang sama: apa yang mereka tulis belum
 * tersentuh apa pun. Jurnal pembuka tinggal di-void; kartu aset yang belum
 * menyusut tinggal dihapus.
 */
interface RevertsImport
{
    /**
     * @return int jumlah dokumen yang dibatalkan
     */
    public function revert(ImportBatch $batch, string $reason): int;
}
