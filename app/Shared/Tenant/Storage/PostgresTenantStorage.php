<?php

namespace App\Shared\Tenant\Storage;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Satu schema Postgres per perusahaan, di dalam database yang sama dengan central.
 *
 * Dipakai di production. Alasannya infrastruktur, bukan selera: berkas SQLite
 * hidup di filesystem container, dan container Render dibangun ulang dari image
 * setiap deploy — seluruh data tenant ikut terhapus. Schema Postgres hidup di
 * layanan database terpisah, jadi deploy tidak menyentuhnya.
 *
 * ## Kenapa schema, bukan database terpisah
 *
 * Satu connection string, satu connection pool, dan `CREATE SCHEMA` boleh
 * berjalan di dalam transaksi — `CREATE DATABASE` tidak. Isolasi per schema
 * sudah cukup selama `search_path` benar, dan itu dijaga di satu tempat: method
 * `connect()` di bawah.
 *
 * ## Kenapa search_path TIDAK menyertakan `public`
 *
 * Tabel central (users, companies, plans, subscriptions) tinggal di `public`.
 * Kalau `public` ikut di search_path, tabel tenant yang kebetulan belum ada
 * akan diam-diam jatuh ke tabel central bernama sama — satu perusahaan bisa
 * membaca data lintas tenant tanpa error sama sekali. Membiarkan query gagal
 * jauh lebih baik daripada diam-diam menjawab dengan data yang salah.
 * `pg_catalog` tetap tersedia otomatis, jadi fungsi bawaan Postgres aman.
 */
class PostgresTenantStorage implements TenantStorage
{
    public function driver(): string
    {
        return 'pgsql';
    }

    public function nameFor(int $companyId): string
    {
        $prefix = (string) config('tenant.schema_prefix', 'tenant_');

        return $prefix.str_pad((string) $companyId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Postgres tidak punya padanan "lokasi di disk" yang berarti bagi aplikasi,
     * jadi kolom `database_path` menyimpan nama schema yang sama. Kolom itu
     * `unique()` dan NOT NULL, dan nama schema memenuhi keduanya.
     */
    public function pathFor(string $name): string
    {
        return $name;
    }

    public function assertReady(): void
    {
        $connection = $this->centralConnection();
        $driver = (string) config('database.connections.'.$connection.'.driver');

        // Gagal keras kalau TENANT_DRIVER=pgsql tapi koneksi yang dituju ternyata
        // bukan Postgres. Tanpa pemeriksaan ini, config koneksi itu tetap disalin
        // beserta `search_path` yang tidak dikenal drivernya, dan kegagalannya
        // muncul jauh kemudian sebagai error SQL yang menyesatkan.
        if ($driver !== 'pgsql') {
            throw new RuntimeException(sprintf(
                'Tenant diatur memakai Postgres, tetapi koneksi "%s" memakai driver "%s". '
                .'Setel TENANT_PGSQL_CONNECTION ke koneksi Postgres, atau kembalikan TENANT_DRIVER ke sqlite.',
                $connection,
                $driver === '' ? 'tidak diketahui' : $driver,
            ));
        }

        try {
            DB::connection($connection)->getPdo();
        } catch (Throwable $e) {
            throw new RuntimeException('Koneksi Postgres untuk tenant tidak tersedia: '.$e->getMessage(), 0, $e);
        }
    }

    public function create(string $name, string $path): void
    {
        $schema = $this->assertValidSchema($name);

        if ($this->exists($name, $path)) {
            throw new RuntimeException("Schema tenant sudah ada: {$schema}");
        }

        DB::connection($this->centralConnection())->statement('CREATE SCHEMA '.$this->quote($schema));
    }

    public function exists(string $name, string $path): bool
    {
        $schema = $this->assertValidSchema($name);

        return DB::connection($this->centralConnection())
            ->table('information_schema.schemata')
            ->where('schema_name', $schema)
            ->exists();
    }

    public function drop(string $name, string $path): bool
    {
        $schema = $this->assertValidSchema($name);

        if (! $this->exists($name, $path)) {
            return false;
        }

        // CASCADE: seluruh tabel, index, dan sequence milik tenant ikut terbuang.
        // Tanpa ini drop selalu gagal karena schema-nya tidak pernah kosong.
        DB::connection($this->centralConnection())->statement('DROP SCHEMA '.$this->quote($schema).' CASCADE');

        return true;
    }

    public function sizeBytes(string $name, string $path): ?int
    {
        $schema = $this->assertValidSchema($name);

        if (! $this->exists($name, $path)) {
            return null;
        }

        // Hanya tabel biasa ('r') dan materialized view ('m').
        // `pg_total_relation_size` SUDAH mencakup index dan TOAST milik relasi
        // itu, jadi ikut menjumlahkan relkind 'i' dan 't' akan menghitungnya
        // dua kali dan melaporkan pemakaian yang lebih besar dari sebenarnya —
        // langsung berakibat kuota penyimpanan menahan client terlalu dini.
        $row = DB::connection($this->centralConnection())->selectOne(
            'SELECT COALESCE(SUM(pg_total_relation_size(c.oid)), 0) AS bytes
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = ?
                AND c.relkind IN (\'r\', \'m\')',
            [$schema]
        );

        if ($row === null) {
            return null;
        }

        $bytes = is_object($row) ? ($row->bytes ?? null) : ($row['bytes'] ?? null);

        return $bytes === null ? null : (int) $bytes;
    }

    public function connect(string $name, string $path): void
    {
        $schema = $this->assertValidSchema($name);

        if (! $this->exists($name, $path)) {
            throw new RuntimeException("Schema tenant tidak ditemukan: {$schema}");
        }

        // Koneksi tenant mewarisi kredensial central — database-nya memang sama,
        // yang berbeda hanya search_path. Menyalin konfigurasinya (bukan menulis
        // ulang env terpisah) memastikan keduanya tidak pernah lepas sinkron.
        $central = config('database.connections.'.$this->centralConnection());

        if (! is_array($central)) {
            throw new RuntimeException('Konfigurasi koneksi central Postgres tidak ditemukan.');
        }

        $central['search_path'] = $schema;
        $central['prefix'] = '';

        Config::set('database.connections.tenant', $central);

        DB::purge('tenant');
        DB::reconnect('tenant');

        // Sabuk pengaman kedua. Laravel sudah mengirim search_path saat koneksi
        // dibuka, tapi koneksi pooled bisa dipakai ulang lintas request; menyetel
        // ulang secara eksplisit membuat kebocoran lintas tenant jauh lebih sulit
        // terjadi diam-diam.
        DB::connection('tenant')->statement('SET search_path TO '.$this->quote($schema));
    }

    private function centralConnection(): string
    {
        $connection = config('tenant.pgsql_connection');

        if (is_string($connection) && $connection !== '') {
            return $connection;
        }

        return (string) config('database.default');
    }

    /**
     * Nama schema tidak bisa dikirim sebagai parameter terikat, jadi satu-satunya
     * pelindung dari injeksi adalah validasi bentuknya di sini. Nama selalu
     * dibentuk mesin (`tenant_000001`), jadi pola ketat ini tidak membatasi apa pun
     * yang sah.
     */
    private function assertValidSchema(string $name): string
    {
        $schema = trim($name);

        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $schema) !== 1) {
            throw new InvalidArgumentException("Nama schema tenant tidak valid: {$name}");
        }

        return $schema;
    }

    private function quote(string $schema): string
    {
        return '"'.$schema.'"';
    }
}
