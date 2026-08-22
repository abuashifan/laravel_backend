<?php

namespace Tests\Feature\Subscription;

use App\Shared\Models\Company;
use App\Shared\Subscription\StorageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kuota penyimpanan per perusahaan (Fase 4, skema tier).
 */
class StorageQuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTierPlans();
    }

    private function service(): StorageQuotaService
    {
        return app(StorageQuotaService::class);
    }

    public function test_quota_matches_the_owners_tier(): void
    {
        $basic = $this->companyOwnedBy($this->clientOn('basic'));
        $pro = $this->companyOwnedBy($this->clientOn('pro'));
        $enterprise = $this->companyOwnedBy($this->clientOn('enterprise'));

        $this->assertSame(1024 * 1024 * 1024, $this->service()->quotaBytesFor($basic));
        $this->assertSame(2048 * 1024 * 1024, $this->service()->quotaBytesFor($pro));
        $this->assertSame(5120 * 1024 * 1024, $this->service()->quotaBytesFor($enterprise));
    }

    public function test_custom_tier_uses_the_clients_manual_quota(): void
    {
        $owner = $this->clientOn('custom');
        $owner->forceFill(['storage_quota_mb' => 10240])->save();
        $company = $this->companyOwnedBy($owner);

        $this->assertSame(10240 * 1024 * 1024, $this->service()->quotaBytesFor($company));
    }

    public function test_custom_tier_without_manual_quota_falls_back_to_the_plan_default(): void
    {
        $owner = $this->clientOn('custom');
        $company = $this->companyOwnedBy($owner);

        $this->assertSame(5120 * 1024 * 1024, $this->service()->quotaBytesFor($company));
    }

    public function test_unmeasured_company_reports_zero_bytes_used(): void
    {
        $company = $this->companyOwnedBy($this->clientOn('basic'));

        $this->assertSame(0, $this->service()->usedBytes($company));
        $this->assertNull($this->service()->measuredAt($company));
        $this->assertTrue($this->service()->canAccept($company, 500 * 1024 * 1024));
    }

    public function test_can_accept_accounts_for_the_incoming_upload_on_top_of_the_last_measurement(): void
    {
        $company = $this->companyOwnedBy($this->clientOn('basic'));
        $company->tenantDatabase()->update(['size_bytes' => 1000 * 1024 * 1024, 'measured_at' => now()]);

        // Basic: 1024 MB. 1000 MB sudah terpakai, sisa 24 MB.
        $this->assertTrue($this->service()->canAccept($company->fresh(), 20 * 1024 * 1024));
        $this->assertFalse($this->service()->canAccept($company->fresh(), 30 * 1024 * 1024));
    }

    public function test_retention_days_matches_the_owners_tier(): void
    {
        $basic = $this->companyOwnedBy($this->clientOn('basic'));
        $enterprise = $this->companyOwnedBy($this->clientOn('enterprise'));

        $this->assertSame(30, $this->service()->retentionDaysFor($basic));
        $this->assertSame(90, $this->service()->retentionDaysFor($enterprise));
    }

    public function test_custom_tier_retention_uses_the_clients_manual_value(): void
    {
        $owner = $this->clientOn('custom');
        $owner->forceFill(['import_retention_days' => 180])->save();
        $company = $this->companyOwnedBy($owner);

        $this->assertSame(180, $this->service()->retentionDaysFor($company));
    }

    public function test_summary_reports_percent_used(): void
    {
        $company = $this->companyOwnedBy($this->clientOn('basic'));
        $company->tenantDatabase()->update(['size_bytes' => 512 * 1024 * 1024, 'measured_at' => now()]);

        $summary = $this->service()->summaryFor($company->fresh());

        $this->assertSame(50.0, $summary['percent_used']);
        $this->assertTrue($summary['can_accept']);
        $this->assertNotNull($summary['measured_at']);
    }

    public function test_company_without_an_owner_falls_back_to_a_safe_default(): void
    {
        $company = Company::query()->create([
            'name' => 'PT Yatim',
            'slug' => 'pt-yatim-storage-'.uniqid(),
            'code' => 'CMP-'.substr((string) microtime(true), -6),
            'status' => 'active',
            'created_by' => null,
        ]);

        $this->assertSame(1024 * 1024 * 1024, $this->service()->quotaBytesFor($company));
        $this->assertSame(30, $this->service()->retentionDaysFor($company));
    }
}
