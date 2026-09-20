<?php

namespace Tests\TenantPgsql;

use App\Shared\Tenant\Storage\PostgresTenantStorage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

/**
 * Basis suite Postgres.
 *
 * Mayoritas test aplikasi ini berjalan di atas tenant SQLite — cepat, tanpa
 * server, dan cukup untuk logika bisnis. Yang TIDAK bisa dibuktikan oleh suite
 * itu adalah hal-hal yang memang berbeda antar database: sintaks DDL, perilaku
 * search_path, dan fungsi tanggal. Persis di situ bug production bersembunyi —
 * `DATE_FORMAT()` lolos di SQLite (cabangnya tidak pernah dipakai) lalu meledak
 * begitu tenant benar-benar berjalan di Postgres.
 *
 * Suite ini menutup celah itu pada jalur yang paling rawan saja, dan hanya jalan
 * kalau `TENANT_TEST_PGSQL_URL` disetel. Tanpa itu seluruh test di sini di-skip,
 * supaya `php artisan test` di mesin tanpa Postgres tetap hijau.
 *
 * Menjalankannya (URL direct Neon, BUKAN yang pooled):
 *   TENANT_TEST_PGSQL_URL="postgresql://..." vendor/bin/phpunit --testsuite=TenantPgsql
 */
#[Group('pgsql')]
abstract class PostgresTenantTestCase extends TestCase
{
    protected string $connection = 'pgsql_test';

    /** @var list<string> */
    private array $createdSchemas = [];

    protected function setUp(): void
    {
        parent::setUp();

        $url = env('TENANT_TEST_PGSQL_URL');

        if (! is_string($url) || trim($url) === '') {
            $this->markTestSkipped('TENANT_TEST_PGSQL_URL tidak disetel — suite Postgres dilewati.');
        }

        Config::set('database.connections.'.$this->connection, [
            'driver' => 'pgsql',
            'url' => $url,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        Config::set('tenant.driver', 'pgsql');
        Config::set('tenant.pgsql_connection', $this->connection);

        try {
            DB::connection($this->connection)->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('Postgres tidak terjangkau: '.$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        // Schema test dibuang eksplisit: test ini menulis ke database sungguhan,
        // jadi RefreshDatabase tidak menjangkaunya.
        foreach ($this->createdSchemas as $schema) {
            try {
                DB::connection($this->connection)->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
            } catch (Throwable) {
                // Pembersihan best-effort.
            }
        }

        $this->createdSchemas = [];

        try {
            DB::disconnect($this->connection);
            DB::disconnect('tenant');
        } catch (Throwable) {
            // abaikan
        }

        parent::tearDown();
    }

    /** Daftarkan schema supaya dibuang di tearDown(). */
    protected function trackSchema(string $schema): string
    {
        $this->createdSchemas[] = $schema;

        return $schema;
    }

    protected function storage(): PostgresTenantStorage
    {
        return app(PostgresTenantStorage::class);
    }

    /** Nama schema unik per test, supaya dua test tidak saling menimpa. */
    protected function uniqueSchema(string $hint = 'it'): string
    {
        return $this->trackSchema('tenant_test_'.$hint.'_'.bin2hex(random_bytes(4)));
    }
}
