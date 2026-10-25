<?php

namespace App\Shared\TransactionLifecycle;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Bungkus edit dokumen transaksi supaya riwayatnya tercatat otomatis
 * (`DocumentHistoryService`). Draft tidak dicatat: belum berefek dan sering
 * diubah. Pencatatan ada di transaksi database yang sama dengan editnya --
 * kalau riwayat gagal disimpan, editnya ikut batal, jadi tidak ada edit
 * tanpa jejak.
 */
trait TracksDocumentHistory
{
    /**
     * @template TModel of Model
     *
     * @param  Closure(): TModel  $edit
     * @return TModel
     */
    protected function withDocumentHistory(Model $document, Closure $edit): Model
    {
        if ($document->getAttribute('status') === 'draft') {
            return $edit();
        }

        return DB::connection('tenant')->transaction(function () use ($document, $edit): Model {
            $history = app(DocumentHistoryService::class);
            $before = $history->snapshot($document);

            // Edit posted = balik efek lama lalu posting ulang; stok dinilai dari hasil akhirnya.
            $result = DeferredChecks::run($edit);

            // Dokumen pengganti (nomor baru) membawa riwayat dokumen lamanya.
            if ($result->getKey() !== $document->getKey()) {
                $history->moveHistory($document, $result);
            }
            $history->recordEdit($before, $result, $result->getKey() !== $document->getKey()
                ? ['replaces_document_id' => $document->getKey()]
                : []);

            return $result;
        });
    }
}
