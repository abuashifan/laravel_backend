<?php

namespace App\Shared\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Record tetangga untuk navigasi Prev/Next di form.
 *
 * Sebelumnya frontend membangun urutan dengan menarik seluruh record satu modul
 * lalu mencari tetangganya di sisi klien — biayanya tumbuh terus seiring data.
 * Di sini cukup dua query ber-index yang masing-masing mengembalikan satu baris
 * dengan dua kolom, sehingga biayanya tetap berapa pun besar tabelnya.
 *
 * Urutan memakai `id` (urutan input), bukan urutan tampil daftar — daftar bisa
 * disortir per kolom oleh user, sedangkan "record sebelumnya yang saya input"
 * tidak boleh ikut berubah karenanya.
 *
 * Controller yang memakai trait ini harus juga memakai `ApiResponse`
 * (untuk `successResponse`), seperti seluruh controller modul di aplikasi ini.
 */
trait ResolvesAdjacentRecords
{
    /**
     * @param  Builder<covariant Model>  $query  Query dasar modul; scoping tenant ikut dari koneksi model.
     * @param  string  $labelColumn  Kolom yang dipakai sebagai judul tab, mis. `invoice_number`.
     */
    protected function adjacentResponse(
        Builder $query,
        Request $request,
        string $labelColumn,
        string $message = 'Adjacent records retrieved successfully'
    ) {
        $raw = $request->query('id');
        // Tanpa `id` berarti form create: posisinya dianggap sesudah record terakhir.
        $id = ($raw === null || $raw === '') ? null : (int) $raw;

        $columns = ['id', $labelColumn];

        $prev = (clone $query)
            ->when($id !== null, fn (Builder $q) => $q->where('id', '<', $id))
            ->orderByDesc('id')
            ->first($columns);

        $next = $id === null ? null : (clone $query)
            ->where('id', '>', $id)
            ->orderBy('id')
            ->first($columns);

        return $this->successResponse([
            'prev' => $this->adjacentRecordPayload($prev, $labelColumn),
            'next' => $this->adjacentRecordPayload($next, $labelColumn),
        ], $message);
    }

    /**
     * `null` berarti tidak ada tetangga di arah itu — frontend menonaktifkan tombolnya.
     */
    private function adjacentRecordPayload(?Model $record, string $labelColumn): ?array
    {
        if ($record === null) {
            return null;
        }

        return [
            'id' => (int) $record->getKey(),
            'label' => (string) ($record->getAttribute($labelColumn) ?? ''),
        ];
    }
}
