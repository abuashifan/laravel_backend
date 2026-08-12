<?php

namespace Tests\Feature\Subscription;

use App\Shared\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `php artisan storage:measure` — pengukuran harian untuk kuota penyimpanan
 * (Fase 4, skema tier).
 */
class MeasureTenantStorageCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTierPlans();
    }

    public function test_measures_sqlite_file_size_and_writes_it_back(): void
    {
        $company = $this->companyOwnedBy($this->clientOn('basic'));
        $tenantPath = $company->tenantDatabase->database_path;
        File::put($tenantPath, str_repeat('x', 2048));

        $this->artisan('storage:measure')->assertSuccessful();

        $tenant = $company->tenantDatabase()->first();
        $this->assertSame(2048, $tenant->size_bytes);
        $this->assertNotNull($tenant->measured_at);
    }

    public function test_measures_import_files_alongside_the_sqlite_file(): void
    {
        Storage::fake('local');
        $company = $this->companyOwnedBy($this->clientOn('basic'));
        File::put($company->tenantDatabase->database_path, str_repeat('x', 1000));

        Storage::disk('local')->put('imports/'.$company->id.'/a.csv', str_repeat('y', 500));
        Storage::disk('local')->put('imports/'.$company->id.'/b.csv', str_repeat('z', 300));

        $this->artisan('storage:measure')->assertSuccessful();

        $this->assertSame(1800, $company->tenantDatabase()->first()->size_bytes);
    }

    public function test_ignores_inactive_tenants(): void
    {
        $company = $this->companyOwnedBy($this->clientOn('basic'));
        $company->tenantDatabase()->update(['status' => 'inactive']);

        $this->artisan('storage:measure')->assertSuccessful();

        $this->assertNull($company->tenantDatabase()->first()->measured_at);
    }

    public function test_succeeds_with_no_tenants_at_all(): void
    {
        $this->assertSame(0, Company::query()->count());

        $this->artisan('storage:measure')->assertSuccessful();
    }
}
