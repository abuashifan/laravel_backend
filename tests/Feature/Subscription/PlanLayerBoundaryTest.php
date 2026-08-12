<?php

namespace Tests\Feature\Subscription;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\CompanyUserPermissionOverride;
use App\Shared\Models\Permission;
use App\Shared\Models\Plan;
use App\Shared\Models\User;
use App\Shared\Permission\EffectivePermissionService;
use App\Shared\Permission\PermissionCatalogService;
use App\Shared\Subscription\PlanPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batas antara lapis 1 (paket) dan lapis 2 (permission) — yang tidak muat di
 * `PlanFeatureMatrixTest` karena butuh lebih dari satu role atau override
 * per skenario (Fase 2, skema tier).
 */
class PlanLayerBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTierPlans();
        $this->enforcePlanFeatures(true);
    }

    /**
     * Test terpenting di seluruh fase ini. Owner (`*`) ada di SETIAP
     * perusahaan; kalau jalan pintas `*` di `hasPermission()` menembus lapis
     * paket, gerbangnya tampak bekerja saat diuji dengan role staff lalu
     * bocor total pada orang yang paling sering memakai aplikasinya.
     */
    public function test_owner_is_still_refused_for_capability_outside_the_plan(): void
    {
        $company = $this->companyOwnedBy($this->clientOn('basic'));
        $companyUser = CompanyUser::query()->where('company_id', $company->id)->firstOrFail();

        $result = app(EffectivePermissionService::class)->explainPermission($companyUser, 'warehouses.create');

        $this->assertFalse($result['allowed']);
        $this->assertSame('not_in_plan', $result['source']);
    }

    public function test_owner_still_passes_layer_two_for_capability_the_plan_opens(): void
    {
        $company = $this->companyOwnedBy($this->clientOn('pro'));
        $companyUser = CompanyUser::query()->where('company_id', $company->id)->firstOrFail();

        $result = app(EffectivePermissionService::class)->explainPermission($companyUser, 'warehouses.create');

        $this->assertTrue($result['allowed']);
        $this->assertSame('role_default', $result['source']);
    }

    /**
     * Role staff ditolak dengan PERMISSION_DENIED, BUKAN FEATURE_NOT_IN_PLAN —
     * mengunci bahwa dua sumbunya (paket vs permission) tidak tertukar.
     */
    public function test_staff_role_without_the_permission_is_refused_by_layer_two_not_the_plan(): void
    {
        // Enterprise membuka budgets.manage; role viewer tidak memilikinya.
        $company = $this->companyOwnedBy($this->clientOn('enterprise'));
        $owner = User::query()->find($company->created_by);
        $viewer = User::factory()->create(['status' => 'active']);

        $companyUser = CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $result = app(EffectivePermissionService::class)->explainPermission($companyUser, 'budgets.manage');

        $this->assertFalse($result['allowed']);
        $this->assertSame('not_assigned', $result['source']);
        $this->assertNotSame('not_in_plan', $result['source']);

        $this->assertNotNull($owner);
    }

    public function test_deny_override_still_beats_the_wildcard(): void
    {
        $company = $this->companyOwnedBy($this->clientOn('pro'));
        $owner = User::query()->findOrFail($company->created_by);
        $companyUser = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $owner->id)->firstOrFail();

        $permission = Permission::query()->create(
            app(PermissionCatalogService::class)->fromKey('warehouses.create', 1)
        );

        CompanyUserPermissionOverride::query()->create([
            'company_user_id' => $companyUser->id,
            'permission_id' => $permission->id,
            'effect' => 'deny',
        ]);

        $result = app(EffectivePermissionService::class)->explainPermission($companyUser, 'warehouses.create');

        $this->assertFalse($result['allowed']);
        $this->assertSame('user_override_deny', $result['source']);
    }

    public function test_client_without_a_plan_falls_back_to_free(): void
    {
        // Free dinonaktifkan (Fase 2 §1) tapi tetap dipakai sebagai jaring
        // pengaman internal — barisnya tidak dihapus.
        $freeOwner = User::factory()->create(['status' => 'active', 'plan_id' => null]);
        $company = Company::query()->create([
            'name' => 'PT Tanpa Paket',
            'slug' => 'pt-tanpa-paket-'.uniqid(),
            'code' => 'CMP-'.substr((string) microtime(true), -6),
            'status' => 'active',
            'created_by' => $freeOwner->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $freeOwner->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $companyUser = CompanyUser::query()->where('company_id', $company->id)->firstOrFail();
        $features = app(PlanPermissionResolver::class)->featuresFor($company);

        $this->assertSame(Plan::query()->where('code', 'free')->value('features'), $features);
        $this->assertFalse(app(EffectivePermissionService::class)->hasPermission($companyUser, 'warehouses.create'));
    }

    public function test_company_without_an_owner_is_refused_not_erroring(): void
    {
        $company = Company::query()->create([
            'name' => 'PT Yatim',
            'slug' => 'pt-yatim-'.uniqid(),
            'code' => 'CMP-'.substr((string) microtime(true), -6),
            'status' => 'active',
            'created_by' => null,
        ]);

        // `hasPermission()` butuh CompanyUser; perusahaan tanpa pemilik tidak
        // punya satu pun, jadi yang diuji di sini adalah resolver-nya
        // langsung, bukan lewat CompanyUser palsu.
        $this->assertSame([], app(PlanPermissionResolver::class)->featuresFor($company));
        $this->assertFalse(app(PlanPermissionResolver::class)->allows($company, 'warehouses.create'));
    }

    public function test_switch_off_restores_the_behaviour_from_before_this_phase(): void
    {
        $this->enforcePlanFeatures(false);

        $company = $this->companyOwnedBy($this->clientOn('basic'));
        $companyUser = CompanyUser::query()->where('company_id', $company->id)->firstOrFail();

        $this->assertSame([], app(PlanPermissionResolver::class)->blockedKeysFor($company));
        $this->assertTrue(app(EffectivePermissionService::class)->hasPermission($companyUser, 'warehouses.create'));
    }
}
