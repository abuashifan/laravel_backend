<?php

namespace App\Shared\TransactionLifecycle;

use Illuminate\Database\Eloquent\Model;

/**
 * Bagian bersama edit & hapus dokumen transaksi (mode lock/open).
 *
 * Tiap service tetap memegang efek posting dokumennya sendiri; trait ini hanya
 * menyatukan cara menanyakan kebijakan dan cara menandai dokumen terhapus.
 */
trait ChangesTransactionDocuments
{
    protected function changePolicy(): TransactionPolicyService
    {
        return app(TransactionPolicyService::class);
    }

    /**
     * Tandai dokumen yang sudah di-void sebagai terhapus: hilang dari daftar,
     * tapi barisnya (dan jurnal void-nya) tetap ada sebagai jejak audit.
     */
    protected function markVoidedDocumentDeleted(Model $document, string $reason): void
    {
        $document->setAttribute('deleted_by', auth()->id());
        $document->setAttribute('delete_reason', $reason !== '' ? $reason : null);
        $document->save();
        $document->delete();
    }

    /**
     * Alasan void saat dokumen yang sudah berefek dihapus.
     */
    protected function deleteVoidReason(string $reason): string
    {
        return $reason !== '' ? $reason : 'Dihapus';
    }
}
