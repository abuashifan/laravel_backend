<?php

namespace App\Shared\Tenant\Storage;

/**
 * Tempat penyimpanan satu tenant database, terlepas dari teknologinya.
 *
 * Ada dua implementasi dan keduanya dipakai sungguhan:
 *
 *   - `SqliteTenantStorage` — satu berkas `.sqlite` per perusahaan. Dipakai
 *     seluruh test suite (cepat, tanpa server) dan pengembangan lokal.
 *   - `PostgresTenantStorage` — satu schema Postgres per perusahaan di dalam
 *     database yang sama dengan central. Dipakai di production, karena berkas
 *     di container Render ikut terhapus setiap deploy.
 *
 * Dua istilah yang dipakai konsisten di seluruh antarmuka ini, memetakan
 * langsung ke kolom tabel `tenant_databases`:
 *
 *   - `$name` → kolom `database_name`. Untuk sqlite berupa nama berkas
 *     (`company_000001.sqlite`), untuk pgsql berupa nama schema
 *     (`tenant_000001`).
 *   - `$path` → kolom `database_path`. Untuk sqlite berupa path absolut
 *     berkasnya, untuk pgsql sama dengan nama schema — Postgres tidak punya
 *     padanan "lokasi di disk" yang berguna bagi aplikasi.
 */
interface TenantStorage
{
    /** Nilai yang disimpan di kolom `tenant_databases.driver`. */
    public function driver(): string;

    /** Nama kanonik tenant untuk sebuah company id. */
    public function nameFor(int $companyId): string;

    /** Lokasi kanonik untuk sebuah nama tenant. */
    public function pathFor(string $name): string;

    /**
     * Pastikan penyimpanan siap dipakai sebelum tenant dibuat — folder ada dan
     * writable (sqlite), atau koneksi hidup (pgsql).
     *
     * @throws \RuntimeException kalau belum siap
     */
    public function assertReady(): void;

    /** Buat wadah tenant yang masih kosong. */
    public function create(string $name, string $path): void;

    public function exists(string $name, string $path): bool;

    /** Buang wadah tenant beserta isinya. Mengembalikan true kalau ada yang dibuang. */
    public function drop(string $name, string $path): bool;

    /** Ukuran terpakai dalam byte, atau null kalau tidak terukur. */
    public function sizeBytes(string $name, string $path): ?int;

    /**
     * Arahkan koneksi `tenant` ke tenant ini. Setelah ini `DB::connection('tenant')`
     * menunjuk data milik perusahaan tersebut dan tidak ada yang lain.
     */
    public function connect(string $name, string $path): void;
}
