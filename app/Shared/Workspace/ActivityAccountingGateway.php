<?php

namespace App\Shared\Workspace;

use App\Shared\Models\Company;

interface ActivityAccountingGateway
{
    /** Return false while the Accounting product is unavailable for this company. */
    public function receive(Company $company, array $approvedActivity): bool;

    /** Ringkasan pencairan satu kegiatan beserta tahapnya; null bila belum diterima atau Accounting tidak aktif. */
    public function status(Company $company, int $document): ?array;

    /**
     * Pencairan, posisi tahap, dan biaya posted per dokumen kegiatan Workspace.
     * Kunci hasil adalah ID dokumen; dokumen tanpa draf Accounting tidak muncul.
     * Null saat Accounting tidak aktif.
     *
     * @param  list<int>  $documents
     * @return array<int, array<string, mixed>>|null
     */
    public function realization(Company $company, array $documents): ?array;

    /**
     * Cocokkan biaya Workspace dengan jurnal biaya Accounting pada rentang tanggal.
     * Null saat Accounting tidak aktif.
     *
     * @param  list<int>  $documents
     */
    public function reconciliation(Company $company, string $from, string $to, array $documents): ?array;

    /**
     * Tandai kegiatan selesai di Accounting dan kembalikan realisasi per baris RAB.
     * Menolak bila masih ada tahap yang belum tuntas. Null saat Accounting tidak aktif.
     *
     * @return array{realized_minor:int, lines:array<int,int>}|null
     */
    public function close(Company $company, int $document, int $actor): ?array;
}
