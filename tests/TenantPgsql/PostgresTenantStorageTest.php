<?php

namespace Tests\TenantPgsql;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class PostgresTenantStorageTest extends PostgresTenantTestCase
{
    public function test_create_exists_and_drop_schema(): void
    {
        $storage = $this->storage();
        $schema = $this->uniqueSchema('crud');

        $this->assertFalse($storage->exists($schema, $schema));

        $storage->create($schema, $schema);
        $this->assertTrue($storage->exists($schema, $schema));

        $this->assertTrue($storage->drop($schema, $schema));
        $this->assertFalse($storage->exists($schema, $schema));
    }

    public function test_drop_returns_false_when_schema_absent(): void
    {
        $schema = $this->uniqueSchema('absent');

        $this->assertFalse($this->storage()->drop($schema, $schema));
    }

    public function test_create_rejects_duplicate_schema(): void
    {
        $storage = $this->storage();
        $schema = $this->uniqueSchema('dup');

        $storage->create($schema, $schema);

        $this->expectException(RuntimeException::class);
        $storage->create($schema, $schema);
    }

    /**
     * Nama schema disisipkan langsung ke SQL — tidak bisa jadi parameter terikat.
     * Validasi bentuknya adalah satu-satunya pelindung dari injeksi, jadi dikunci
     * di test, bukan cuma diandalkan sebagai konvensi.
     */
    public function test_rejects_schema_names_outside_the_expected_shape(): void
    {
        $storage = $this->storage();

        foreach (['tenant-1', 'tenant 1', '1tenant', 'tenant";DROP SCHEMA public;--', '', 'Tenant_1'] as $invalid) {
            try {
                $storage->exists($invalid, $invalid);
                $this->fail("Nama schema seharusnya ditolak: {$invalid}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_drop_removes_tables_inside_the_schema(): void
    {
        $storage = $this->storage();
        $schema = $this->uniqueSchema('cascade');

        $storage->create($schema, $schema);
        DB::connection($this->connection)->statement('CREATE TABLE "'.$schema.'".notes (id int)');

        // Tanpa CASCADE, DROP SCHEMA selalu gagal karena schema-nya tidak kosong.
        $this->assertTrue($storage->drop($schema, $schema));
        $this->assertFalse($storage->exists($schema, $schema));
    }

    public function test_size_bytes_is_null_when_absent_and_numeric_when_present(): void
    {
        $storage = $this->storage();
        $schema = $this->uniqueSchema('size');

        $this->assertNull($storage->sizeBytes($schema, $schema));

        $storage->create($schema, $schema);
        DB::connection($this->connection)->statement('CREATE TABLE "'.$schema.'".notes (id int, body text)');

        $size = $storage->sizeBytes($schema, $schema);

        $this->assertIsInt($size);
        $this->assertGreaterThan(0, $size);
    }

    /**
     * `pg_total_relation_size` sudah mencakup index dan TOAST milik tabelnya.
     * Ikut menjumlahkan relasi index secara terpisah membuat angkanya berlipat,
     * dan kuota penyimpanan akan menahan client jauh sebelum waktunya — jadi
     * hasilnya dibandingkan dengan ukuran tabel itu sendiri, bukan sekadar > 0.
     */
    public function test_size_bytes_does_not_double_count_indexes(): void
    {
        $storage = $this->storage();
        $schema = $this->uniqueSchema('nodouble');

        $storage->create($schema, $schema);
        DB::connection($this->connection)->statement('CREATE TABLE "'.$schema.'".notes (id serial primary key, body text)');
        DB::connection($this->connection)->statement('CREATE INDEX notes_body_idx ON "'.$schema.'".notes (body)');

        $expected = (int) DB::connection($this->connection)
            ->selectOne('SELECT pg_total_relation_size(?::regclass) AS bytes', ['"'.$schema.'".notes'])
            ->bytes;

        $this->assertSame($expected, $storage->sizeBytes($schema, $schema));
    }

    public function test_connect_points_tenant_connection_at_the_schema(): void
    {
        $storage = $this->storage();
        $schema = $this->uniqueSchema('connect');

        $storage->create($schema, $schema);
        $storage->connect($schema, $schema);

        DB::connection('tenant')->statement('CREATE TABLE notes (id int)');

        // Tabel dibuat tanpa prefix schema, jadi kalau search_path benar ia
        // mendarat di schema tenant — bukan di public.
        $landed = DB::connection($this->connection)
            ->table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', 'notes')
            ->exists();

        $this->assertTrue($landed, 'Tabel tidak mendarat di schema tenant — search_path tidak diterapkan.');
    }

    public function test_connect_fails_when_schema_is_missing(): void
    {
        $schema = $this->uniqueSchema('missing');

        $this->expectException(RuntimeException::class);
        $this->storage()->connect($schema, $schema);
    }
}
