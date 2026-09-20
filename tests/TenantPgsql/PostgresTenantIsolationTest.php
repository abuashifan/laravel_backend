<?php

namespace Tests\TenantPgsql;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Isolasi antar tenant — risiko utama schema-per-tenant.
 *
 * Dengan berkas SQLite terpisah, kebocoran lintas tenant praktis mustahil:
 * dua perusahaan benar-benar dua berkas. Dengan satu database dan pembeda
 * berupa `search_path`, satu kesalahan konfigurasi bisa membuat perusahaan A
 * membaca data perusahaan B tanpa error sama sekali. Karena itu isolasinya
 * dikunci di test, bukan diandalkan pada pembacaan kode.
 */
class PostgresTenantIsolationTest extends PostgresTenantTestCase
{
    public function test_two_tenants_do_not_see_each_others_rows(): void
    {
        $storage = $this->storage();
        $first = $this->uniqueSchema('iso_a');
        $second = $this->uniqueSchema('iso_b');

        foreach ([$first => 'milik A', $second => 'milik B'] as $schema => $body) {
            $storage->create($schema, $schema);
            $storage->connect($schema, $schema);

            DB::connection('tenant')->statement('CREATE TABLE notes (id serial primary key, body text)');
            DB::connection('tenant')->table('notes')->insert(['body' => $body]);
        }

        $storage->connect($first, $first);
        $this->assertSame(['milik A'], DB::connection('tenant')->table('notes')->pluck('body')->all());

        $storage->connect($second, $second);
        $this->assertSame(['milik B'], DB::connection('tenant')->table('notes')->pluck('body')->all());
    }

    /**
     * Tabel central (users, companies, plans) tinggal di `public`. Kalau `public`
     * ikut di search_path, tabel tenant yang kebetulan belum ada akan diam-diam
     * jatuh ke tabel central bernama sama — kebocoran yang tidak memunculkan
     * error apa pun. Query HARUS gagal, bukan menjawab dengan data central.
     */
    public function test_central_tables_are_not_reachable_from_tenant_connection(): void
    {
        $storage = $this->storage();
        $schema = $this->uniqueSchema('nopublic');

        DB::connection($this->connection)->statement(
            'CREATE TABLE IF NOT EXISTS public.tenant_isolation_probe (id int)'
        );

        try {
            $storage->create($schema, $schema);
            $storage->connect($schema, $schema);

            $reachable = true;
            try {
                DB::connection('tenant')->table('tenant_isolation_probe')->count();
            } catch (Throwable) {
                $reachable = false;
            }

            $this->assertFalse(
                $reachable,
                'Tabel di schema public terbaca dari koneksi tenant — search_path bocor ke central.'
            );
        } finally {
            DB::connection($this->connection)->statement('DROP TABLE IF EXISTS public.tenant_isolation_probe');
        }
    }

    public function test_dropping_one_tenant_leaves_the_other_intact(): void
    {
        $storage = $this->storage();
        $kept = $this->uniqueSchema('kept');
        $removed = $this->uniqueSchema('removed');

        foreach ([$kept, $removed] as $schema) {
            $storage->create($schema, $schema);
            $storage->connect($schema, $schema);
            DB::connection('tenant')->statement('CREATE TABLE notes (id serial primary key)');
        }

        $storage->drop($removed, $removed);

        $this->assertFalse($storage->exists($removed, $removed));
        $this->assertTrue($storage->exists($kept, $kept));

        $storage->connect($kept, $kept);
        $this->assertSame(0, DB::connection('tenant')->table('notes')->count());
    }
}
