<?php

namespace App\Shared\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Search / filter / sort / paginate untuk endpoint daftar — dikerjakan di SQL.
 *
 * Sebelumnya `ApiResponse::listResponse()` menerima seluruh tabel sebagai
 * Collection lalu menyaring & memotongnya di memori PHP: mengambil 1 baris
 * dan mengambil semua baris sama-sama mahal (diukur 2026-08-06, lihat
 * `Finlite_knowladge/plans/list-query-pushdown/README.md`). Trait ini
 * memindahkan kerja itu ke query builder — biayanya tetap kecil berapa pun
 * besar tabelnya, karena database yang memfilter, bukan PHP.
 *
 * Tiap service yang memakai trait ini cukup mendeklarasikan kolom mana yang
 * relevan; logikanya sendiri (search, status, tanggal, sort, paginate) tinggal
 * di satu tempat. Mengikuti pola `ResolvesAdjacentRecords`.
 *
 * **Kelas pemakai wajib mendeklarasikan sendiri** properti berikut (trait ini
 * sengaja tidak mendeklarasikannya — PHP menolak jika trait dan kelas pemakai
 * mendeklarasikan properti bertipe yang sama dengan default berbeda, "definition
 * differs and is considered incompatible"):
 *
 * @property-read array<int,string> $listSearchable Kolom tabel sendiri yang
 *   dicocokkan kotak pencarian, mis. `['invoice_number']`.
 * @property-read array<string,array<int,string>> $listSearchableRelations Relasi
 *   yang ikut dicari, mis. `['customer' => ['name', 'contact_code']]`. Dijalankan
 *   lewat `whereHas`, pastikan kolom relasi ber-index. Kosongkan `[]` bila tidak ada.
 * @property-read string $listDateColumn Kolom tanggal dokumen untuk filter
 *   periode. Kosongkan `''` bila modul tidak punya.
 * @property-read string $listStatusColumn Kolom status. Modul transaksi
 *   memakai `'status'` (string); master data memakai `'is_active'` (boolean,
 *   dipetakan dari `?status=active|inactive` — lihat `applyListStatusQuery()`).
 * @property-read array<string,string> $listDefaultSort Urutan bila `sort_by`
 *   tidak dikirim, mis. `['invoice_date' => 'desc', 'id' => 'desc']`.
 * @property-read array<int,string> $listSortable Kolom yang boleh dipakai
 *   `sort_by`. Input di luar daftar ini diabaikan (jatuh ke `$listDefaultSort`)
 *   — jangan pernah masukkan input mentah ke `orderBy()`, itu celah injeksi
 *   nama kolom.
 */
trait AppliesListQuery
{
    /**
     * `listResponse()` punya kontrak: tanpa `page`/`per_page` di request berarti
     * "kirim semua, tidak usah dipaginasi" (beberapa pemanggil internal
     * bergantung pada ini, mis. modul yang butuh seluruh baris untuk agregasi).
     * Method ini menghormati kontrak yang sama — search/status/tanggal/sort
     * tetap jalan di SQL, tapi `->paginate()` cuma dipanggil kalau salah satu
     * dari `page`/`per_page` benar-benar ada di `$filters`. Simetris dengan
     * pengecekan `$request->hasAny(['page', 'per_page'])` di `ApiResponse`.
     *
     * @param  Builder<covariant Model>  $query  Query dasar modul (filter khusus modul sudah
     *                                           diterapkan sebelum memanggil ini).
     * @param  array<string,mixed>  $filters  Biasanya `$request->query()`.
     * @return LengthAwarePaginator|Collection<int,Model>
     */
    protected function applyListQuery(Builder $query, array $filters): LengthAwarePaginator|Collection
    {
        $this->applyListSearchQuery($query, (string) ($filters['search'] ?? ''));
        $this->applyListStatusQuery($query, $filters['status'] ?? null);
        $this->applyListDateRangeQuery(
            $query,
            $filters['date_from'] ?? $filters['start_date'] ?? null,
            $filters['date_to'] ?? $filters['end_date'] ?? null,
        );
        $this->applyListSortQuery(
            $query,
            (string) ($filters['sort_by'] ?? $filters['sort'] ?? ''),
            (string) ($filters['sort_direction'] ?? $filters['direction'] ?? 'asc'),
        );

        if (! array_key_exists('page', $filters) && ! array_key_exists('per_page', $filters)) {
            return $query->get();
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 10)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applyListSearchQuery(Builder $query, string $search): void
    {
        $term = trim($search);
        if ($term === '' || ($this->listSearchable === [] && $this->listSearchableRelations === [])) {
            return;
        }

        // Dibungkus satu `where(fn)` supaya OR di dalamnya tidak bocor ke
        // kondisi status/tanggal/filter khusus modul yang sudah dipasang
        // sebelum applyListQuery() dipanggil.
        $query->where(function (Builder $q) use ($term): void {
            foreach ($this->listSearchable as $column) {
                $q->orWhere($column, 'like', "%{$term}%");
            }
            foreach ($this->listSearchableRelations as $relation => $columns) {
                $q->orWhereHas($relation, function (Builder $relationQuery) use ($columns, $term): void {
                    $relationQuery->where(function (Builder $inner) use ($columns, $term): void {
                        foreach ($columns as $column) {
                            $inner->orWhere($column, 'like', "%{$term}%");
                        }
                    });
                });
            }
        });
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applyListStatusQuery(Builder $query, mixed $status): void
    {
        if ($status === null || $status === '') {
            return;
        }

        $statuses = array_values(array_filter(
            array_map('trim', explode(',', (string) $status)),
            fn (string $s): bool => $s !== '',
        ));
        if ($statuses === []) {
            return;
        }

        if ($this->listStatusColumn === 'is_active') {
            // Master data: pemetaan boolean dipertahankan sama seperti jalur
            // in-memory lama (?status=active|inactive), bukan is_active=1/0 mentah.
            $mapped = array_map(fn (string $s): bool => mb_strtolower($s) === 'active', $statuses);
            $query->whereIn('is_active', $mapped);

            return;
        }

        $query->whereIn($this->listStatusColumn, $statuses);
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applyListDateRangeQuery(Builder $query, mixed $startDate, mixed $endDate): void
    {
        if ($this->listDateColumn === '') {
            return;
        }
        if (($startDate === null || $startDate === '') && ($endDate === null || $endDate === '')) {
            return;
        }

        if ($startDate !== null && $startDate !== '') {
            $query->whereDate($this->listDateColumn, '>=', (string) $startDate);
        }
        if ($endDate !== null && $endDate !== '') {
            $query->whereDate($this->listDateColumn, '<=', (string) $endDate);
        }
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applyListSortQuery(Builder $query, string $sortBy, string $direction): void
    {
        $direction = mb_strtolower($direction) === 'desc' ? 'desc' : 'asc';

        if ($sortBy !== '' && in_array($sortBy, $this->listSortable, true)) {
            $query->orderBy($sortBy, $direction);

            return;
        }

        foreach ($this->listDefaultSort as $column => $defaultDirection) {
            $query->orderBy($column, $defaultDirection);
        }
    }
}
