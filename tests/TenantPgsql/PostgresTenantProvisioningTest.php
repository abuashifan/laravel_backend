<?php

namespace Tests\TenantPgsql;

use App\Shared\Company\CompanyPurgeService;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantProvisioningService;
use App\Shared\Tenant\TenantRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Jalur lengkap provisioning, perbaikan, dan purge di atas Postgres sungguhan.
 *
 * Tabel central tetap di SQLite in-memory seperti test lain — yang diuji di sini
 * justru campurannya: central di satu tempat, tenant di schema Postgres. Itu
 * persis bentuk production.
 */
class PostgresTenantProvisioningTest extends PostgresTenantTestCase
{
    use RefreshDatabase;

    public function test_provision_creates_a_postgres_schema_for_the_company(): void
    {
        $owner = User::factory()->create(['status' => 'active']);

        $result = app(TenantProvisioningService::class)->provision(
            'PT Uji Postgres',
            'pt-uji-postgres',
            $owner->email,
        );

        $tenantDatabase = $result['tenant_database'];
        $this->trackSchema((string) $tenantDatabase->database_name);

        $this->assertSame('pgsql', $tenantDatabase->driver);
        $this->assertSame('tenant_'.str_pad((string) $result['company']->id, 6, '0', STR_PAD_LEFT), $tenantDatabase->database_name);

        // database_path menyimpan nama schema — kolomnya NOT NULL dan unique,
        // dan nama schema memenuhi keduanya tanpa perlu migration tambahan.
        $this->assertSame($tenantDatabase->database_name, $tenantDatabase->database_path);

        $this->assertTrue(
            $this->storage()->exists((string) $tenantDatabase->database_name, (string) $tenantDatabase->database_path)
        );
    }

    public function test_provision_rolls_back_schema_when_central_write_fails(): void
    {
        $owner = User::factory()->create(['status' => 'active']);

        // Slug yang sudah dipakai membuat provisioning gagal SETELAH schema
        // dibuat kalau urutannya salah. Schema tidak boleh tertinggal yatim.
        app(TenantProvisioningService::class)->provision('PT Pertama', 'pt-bentrok', $owner->email);
        $first = TenantDatabase::query()->firstOrFail();
        $this->trackSchema((string) $first->database_name);

        $before = $this->schemaCount();

        try {
            app(TenantProvisioningService::class)->provision('PT Kedua', 'pt-bentrok', $owner->email);
            $this->fail('Slug duplikat seharusnya ditolak.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($before, $this->schemaCount(), 'Schema tenant tertinggal setelah provisioning gagal.');
    }

    /**
     * Tombol "Buat Ulang Database" sekaligus jalur pindah dari SQLite ke
     * Postgres: baris lama bertanda `sqlite` (berkasnya sudah lenyap kena wipe
     * deploy) harus bangkit sebagai schema Postgres, bukan sebagai berkas baru.
     */
    public function test_repair_migrates_a_stale_sqlite_row_to_postgres(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'PT Warisan SQLite',
            'slug' => 'pt-warisan-sqlite',
            'code' => 'CMP-LEGACY',
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => 'company_000123.sqlite',
            'database_path' => database_path('tenants/company_000123.sqlite'),
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        $result = app(TenantRepairService::class)->repair($company);

        $this->assertTrue($result['success'], $result['reason'] ?? '');

        $repaired = TenantDatabase::query()->where('company_id', $company->id)->firstOrFail();
        $this->trackSchema((string) $repaired->database_name);

        $this->assertSame('pgsql', $repaired->driver);
        $this->assertSame('tenant_'.str_pad((string) $company->id, 6, '0', STR_PAD_LEFT), $repaired->database_name);
        $this->assertTrue($this->storage()->exists((string) $repaired->database_name, (string) $repaired->database_path));
    }

    public function test_purge_drops_the_tenant_schema(): void
    {
        $owner = User::factory()->create(['status' => 'active']);

        $result = app(TenantProvisioningService::class)->provision(
            'PT Akan Dihapus',
            'pt-akan-dihapus',
            $owner->email,
        );

        $schema = (string) $result['tenant_database']->database_name;
        $this->trackSchema($schema);
        $this->assertTrue($this->storage()->exists($schema, $schema));

        $company = $result['company'];
        $company->delete();

        app(CompanyPurgeService::class)->purge($company->fresh(), $owner);

        $this->assertFalse($this->storage()->exists($schema, $schema), 'Schema tenant tidak ikut terbuang saat purge.');
    }

    private function schemaCount(): int
    {
        return DB::connection($this->connection)
            ->table('information_schema.schemata')
            ->where('schema_name', 'like', 'tenant_%')
            ->count();
    }
}
