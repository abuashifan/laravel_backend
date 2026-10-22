<?php

namespace App\Shared\TransactionLifecycle;

use Illuminate\Support\Facades\DB;

/**
 * Pengecekan "transaksi lain yang tertaut" yang dipakai bersama banyak modul.
 *
 * Hasilnya kalimat siap tampil, dikumpulkan tiap service ke daftar dependensi
 * yang diserahkan ke `TransactionPolicyService::checkDocumentChange()`.
 * Sengaja memakai query builder, bukan model modul lain, supaya Shared tidak
 * bergantung ke internal modul.
 */
class TransactionDependents
{
    /**
     * Jurnal yang barisnya sudah dipakai rekonsiliasi bank (sudah dicentang
     * "cleared", atau rekonsiliasinya sudah final). Mengubah jurnal itu akan
     * merusak saldo rekonsiliasi yang sudah dicocokkan dengan rekening koran.
     *
     * @param  array<int, int|null>  $journalIds
     * @return list<string>
     */
    public function reconciledJournals(array $journalIds): array
    {
        $journalIds = array_values(array_filter(array_map('intval', $journalIds)));
        if ($journalIds === []) {
            return [];
        }

        $numbers = DB::connection('tenant')->table('bank_reconciliation_lines as l')
            ->join('bank_reconciliations as r', 'r.id', '=', 'l.bank_reconciliation_id')
            ->whereIn('l.journal_entry_id', $journalIds)
            ->where(fn ($q) => $q->where('l.is_cleared', true)->orWhere('r.status', '!=', 'draft'))
            ->distinct()
            ->pluck('r.reconciliation_number')
            ->all();

        return array_map(fn ($number): string => "sudah direkonsiliasi di rekonsiliasi bank {$number}", $numbers);
    }

    /**
     * `reconciledJournals()` untuk semua jurnal buatan sistem milik satu dokumen sumber.
     *
     * @return list<string>
     */
    public function reconciledSource(string $sourceType, int $sourceId): array
    {
        $journalIds = DB::connection('tenant')->table('journal_entries')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->pluck('id')
            ->all();

        return $this->reconciledJournals($journalIds);
    }

    /**
     * Daftar nomor dokumen jadi satu kalimat dependensi, mis.
     * "penerimaan pembayaran RCV-001, RCV-002". Kosong = tidak ada.
     *
     * @param  iterable<int, string|null>  $numbers
     * @return list<string>
     */
    public function documents(string $label, iterable $numbers): array
    {
        $numbers = array_values(array_unique(array_filter(array_map('strval', is_array($numbers) ? $numbers : iterator_to_array($numbers)))));

        return $numbers === [] ? [] : [$label.' '.implode(', ', $numbers)];
    }
}
