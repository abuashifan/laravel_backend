<?php

namespace Tests\Feature\Companies;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Plan;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyQuotaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tenantFiles = [];

    private string $tenantDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDirectory = storage_path('framework/testing/tenants-'.uniqid());
        File::ensureDirectoryExists($this->tenantDirectory);
        config(['tenant.database_path' => $this->tenantDirectory]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tenantFiles as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        if ($this->tenantDirectory !== '' && File::isDirectory($this->tenantDirectory)) {
            File::deleteDirectory($this->tenantDirectory);
        }

        parent::tearDown();
    }

    public function test_client_without_plan_is_limited_to_one_company(): void
    {
        $this->seedPlans();

        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user, ['*']);

        $companyId = (int) $this->postJson('/api/companies', ['name' => 'PT Pertama'])
            ->assertStatus(201)
            ->json('data.id');
        $this->trackTenantFile($companyId);

        $this->postJson('/api/companies', ['name' => 'PT Kedua'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'COMPANY_QUOTA_EXCEEDED')
            ->assertJsonPath('meta.quota.used', 1)
            ->assertJsonPath('meta.quota.limit', 1);

        $this->assertSame(1, Company::query()->count());
        $this->assertSame(1, TenantDatabase::query()->count());
    }

    public function test_pro_plan_allows_more_companies(): void
    {
        $plans = $this->seedPlans();

        $user = User::factory()->create(['status' => 'active', 'plan_id' => $plans['pro']->id]);
        Sanctum::actingAs($user, ['*']);

        foreach (['PT Satu', 'PT Dua', 'PT Tiga'] as $name) {
            $companyId = (int) $this->postJson('/api/companies', ['name' => $name])
                ->assertStatus(201)
                ->json('data.id');
            $this->trackTenantFile($companyId);
        }

        $this->postJson('/api/companies', ['name' => 'PT Empat'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'COMPANY_QUOTA_EXCEEDED');

        $this->assertSame(3, Company::query()->count());
    }

    public function test_company_where_user_is_only_staff_does_not_consume_quota(): void
    {
        $this->seedPlans();

        $owner = User::factory()->create(['status' => 'active']);
        $staff = User::factory()->create(['status' => 'active']);

        $company = Company::query()->create([
            'name' => 'PT Milik Orang Lain',
            'slug' => 'pt-milik-orang-lain',
            'code' => 'CMP-009001',
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $staff->id,
            'role' => 'staff',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($staff, ['*']);

        $companyId = (int) $this->postJson('/api/companies', ['name' => 'PT Milik Sendiri'])
            ->assertStatus(201)
            ->json('data.id');
        $this->trackTenantFile($companyId);
    }

    public function test_downgrade_does_not_remove_existing_companies(): void
    {
        $plans = $this->seedPlans();

        $user = User::factory()->create(['status' => 'active', 'plan_id' => $plans['pro']->id]);
        Sanctum::actingAs($user, ['*']);

        foreach (['PT Satu', 'PT Dua'] as $name) {
            $companyId = (int) $this->postJson('/api/companies', ['name' => $name])
                ->assertStatus(201)
                ->json('data.id');
            $this->trackTenantFile($companyId);
        }

        $user->forceFill(['plan_id' => $plans['basic']->id])->save();

        // Instance yang dipegang Sanctum::actingAs masih menyimpan relasi plan
        // yang lama. Di request sungguhan user selalu dibaca ulang dari
        // database, jadi sesi disegarkan di sini supaya testnya setara.
        Sanctum::actingAs($user->fresh(), ['*']);

        // Dua perusahaan tetap ada dan tetap terbaca oleh pemiliknya.
        $response = $this->getJson('/api/companies')->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.quota.used'));
        $this->assertSame(1, $response->json('meta.quota.limit'));
        $this->assertFalse($response->json('meta.quota.can_create'));

        $this->postJson('/api/companies', ['name' => 'PT Tiga'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'COMPANY_QUOTA_EXCEEDED');
    }

    public function test_company_list_exposes_quota_summary(): void
    {
        $plans = $this->seedPlans();

        $user = User::factory()->create(['status' => 'active', 'plan_id' => $plans['basic']->id]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/companies')
            ->assertStatus(200)
            ->assertJsonPath('meta.quota.used', 0)
            ->assertJsonPath('meta.quota.limit', 1)
            ->assertJsonPath('meta.quota.can_create', true)
            ->assertJsonPath('meta.quota.plan_code', 'basic');
    }

    /**
     * @return array<string, Plan>
     */
    private function seedPlans(): array
    {
        return [
            'free' => Plan::query()->create([
                'name' => 'Free', 'code' => 'free', 'max_users' => 1, 'max_companies' => 1, 'status' => 'active',
            ]),
            'basic' => Plan::query()->create([
                'name' => 'Basic', 'code' => 'basic', 'max_users' => 3, 'max_companies' => 1, 'status' => 'active',
            ]),
            'pro' => Plan::query()->create([
                'name' => 'Pro', 'code' => 'pro', 'max_users' => 10, 'max_companies' => 3, 'status' => 'active',
            ]),
        ];
    }

    private function trackTenantFile(int $companyId): void
    {
        $path = TenantDatabase::query()->where('company_id', $companyId)->value('database_path');

        if (is_string($path) && $path !== '') {
            $this->tenantFiles[] = $path;
        }
    }
}
