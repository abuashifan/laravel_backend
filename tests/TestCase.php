<?php

namespace Tests;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Permission;
use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Permission\PermissionCatalogService;
use App\Shared\Subscription\PlanPermissionResolver;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Existing fixtures name role templates; translate them into direct
        // permissions only in tests. Explicit direct-access fixtures opt out.
        CompanyUser::created(function (CompanyUser $membership): void {
            if (array_key_exists('full_access', $membership->getAttributes()) || ! Schema::hasTable('company_user_permissions')) {
                return;
            }

            if (in_array($membership->role, ['owner', 'admin'], true)) {
                $membership->forceFill([
                    'full_access' => true,
                    'is_owner' => $membership->role === 'owner',
                ])->saveQuietly();

                return;
            }

            // Tes boleh mendefinisikan peran kustom lewat config('permissions.roles.x').
            $keys = (array) (config('permissions.roles.'.$membership->role) ?? self::legacyRoleTemplates()[$membership->role] ?? []);
            $renames = (array) config('permission_renames', []);
            foreach ($keys as $key) {
                $keys = array_merge($keys, $renames[$key] ?? []);
            }
            $this->assignTestPermissions($membership, $keys);
        });
    }

    /**
     * Template peran lama untuk fixture tes (lihat tests/Fixtures/legacy_role_templates.php).
     *
     * @return array<string, list<string>>
     */
    protected static function legacyRoleTemplates(): array
    {
        static $templates = null;

        return $templates ??= require __DIR__.'/Fixtures/legacy_role_templates.php';
    }

    /** @param list<string> $keys */
    protected function assignTestPermissions(CompanyUser $membership, array $keys): void
    {
        $catalog = app(PermissionCatalogService::class);
        $ids = [];
        foreach (array_unique($keys) as $key) {
            if ($key === '*') {
                $membership->forceFill(['full_access' => true])->saveQuietly();

                continue;
            }
            $ids[] = Permission::query()->firstOrCreate(['key' => $key], $catalog->fromKey($key))->id;
        }
        $membership->assignedPermissions()->sync($ids);
    }

    /**
     * Berkas tenant sqlite dibuat lewat `companyOwnedBy()` atau
     * `registerTenantFile()`, dibersihkan di `tearDown()`. Sebelum Fase 5
     * (kebersihan tenant), setiap berkas test yang lupa dihapus menumpuk
     * permanen di `database/tenants/` — terukur 53 GB / 33.790 berkas
     * 2026-08-11. Disatukan di sini supaya berkas test baru, dan base class
     * lama yang direfaktor untuk memakainya, tidak mengulang blok manual.
     *
     * @var array<int, string>
     */
    private array $tenantTestFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tenantTestFiles as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }
        $this->tenantTestFiles = [];

        parent::tearDown();
    }

    /**
     * Daftarkan berkas tenant sqlite supaya dihapus otomatis di `tearDown()`.
     * Dipanggil dari `JournalTestCase`, `SalesTestCase`, `PurchaseTestCase`,
     * `MasterDataTestCase`, `BudgetTestCase` (Fase 5, skema tier) — kelima
     * base class itu menjangkau ~110 dari total ~117 pemanggil `setUpTenant()`
     * semacamnya, jadi memperbaikinya di satu titik menutup sebagian besar
     * kebocoran tanpa menyentuh puluhan berkas satu-satu.
     */
    protected function registerTenantFile(string $path): void
    {
        $this->tenantTestFiles[] = $path;
    }

    /**
     * Empat tier + Custom, angka dan fitur persis `PlanSeeder` — sumber
     * kebenaran tunggal supaya matriks tier di test tidak diam-diam menyimpang
     * dari yang benar-benar di-deploy. `RefreshDatabase` mengosongkan
     * `plans` di setiap test, jadi ini wajib dipanggil sebelum memakai kode
     * tier mana pun.
     */
    protected function seedTierPlans(): void
    {
        $this->seed(PlanSeeder::class);
    }

    /**
     * Client (pemilik) pada tier tertentu. Paket harus sudah di-seed lewat
     * `seedTierPlans()` — melempar kalau kodenya tidak ditemukan, supaya typo
     * kode tier gagal keras, bukan diam-diam jadi Free lewat fallback service.
     */
    protected function clientOn(string $planCode): User
    {
        $plan = Plan::query()->where('code', $planCode)->firstOrFail();

        return User::factory()->create(['status' => 'active', 'plan_id' => $plan->id]);
    }

    /**
     * Perusahaan milik `$owner`, lengkap dengan baris `tenant_databases` sqlite
     * kosong supaya `TenantContext`/`EnsureCompanyAccess` bisa mengikatnya.
     *
     * SENGAJA tidak memakai `TenantProvisioningService`: `RefreshDatabase`
     * mengulang id dari 1 di setiap test, dan provisioning nyata akan
     * bertabrakan dengan berkas tenant dev yang sungguhan ada di disk.
     */
    protected function companyOwnedBy(User $owner): Company
    {
        $company = Company::query()->create([
            'name' => 'PT Uji '.$owner->id.'-'.uniqid(),
            'slug' => 'pt-uji-'.$owner->id.'-'.uniqid(),
            'code' => 'CMP-'.str_pad((string) $owner->id, 4, '0', STR_PAD_LEFT).'-'.substr((string) microtime(true), -4),
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $tenantPath = database_path('tenants/test_'.class_basename(static::class).'_'.$company->id.'_'.uniqid(getmypid().'_', true).'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath));
        File::put($tenantPath, '');
        $this->registerTenantFile($tenantPath);

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => basename($tenantPath),
            'database_path' => $tenantPath,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        return $company;
    }

    /**
     * Langganan aktif untuk `$user` — dibutuhkan `POST /api/companies` sejak
     * gerbang SUBSCRIPTION_REQUIRED (client dengan `plan_id` tertempel tapi
     * belum pernah "Mulai Langganan" tidak boleh bikin perusahaan). Plan
     * dipakai dari `$user->plan` kalau sudah diisi (mis. lewat `clientOn()`),
     * kalau tidak ada plan apa pun dibuat seadanya — kolom `plan_id` di
     * `subscriptions` NOT NULL tapi test company-creation ini biasanya tidak
     * peduli tier-nya yang mana.
     */
    protected function activeSubscriptionFor(User $user, ?Plan $plan = null): Subscription
    {
        $plan ??= $user->plan ?? Plan::query()->first() ?? Plan::query()->create([
            'name' => 'Test Plan',
            'code' => 'test-plan-'.uniqid(),
            'max_users' => 10,
            'max_companies' => 10,
            'status' => 'active',
        ]);

        return Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addYear(),
            'billing_cycle' => 'monthly',
            'price' => 0,
        ]);
    }

    /**
     * Menyalakan/mematikan saklar lapis paket di tengah test, dan membuang
     * cache per-request `PlanPermissionResolver` — tanpa `flush()`, mengubah
     * config setelah resolver sudah pernah dipanggil di test yang sama tidak
     * akan terlihat efeknya.
     */
    protected function enforcePlanFeatures(bool $on = true): void
    {
        config(['plan_features.enforce' => $on]);
        app(PlanPermissionResolver::class)->flush();
    }
}
