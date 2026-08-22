<?php

namespace App\Shared\Api;

use App\Shared\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Lampirkan nama pembuat record sebagai `created_by_name` pada hasil list.
 *
 * Tabel modul (mis. `cash_payments`) ada di database tenant sedangkan `users`
 * di database pusat, jadi relasi Eloquent lintas koneksi tidak bisa
 * di-eager-load. Nama diambil sekali untuk seluruh halaman (satu query,
 * bukan N+1) lalu dipetakan ke tiap baris. Nilainya `null` bila `created_by`
 * kosong atau user-nya sudah dihapus. Pola sama dengan
 * `JournalEntryService::attachCreatorNames()`.
 */
trait AttachesCreatorNames
{
    /**
     * @param  iterable<int,Model>  $records
     */
    private function attachCreatorNames(iterable $records): void
    {
        $ids = collect($records)
            ->pluck('created_by')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $names = $ids === []
            ? []
            : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        foreach ($records as $record) {
            $createdBy = $record->created_by;
            $record->setAttribute('created_by_name', $createdBy ? ($names[$createdBy] ?? null) : null);
        }
    }
}
