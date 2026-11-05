<?php

namespace Tests\Feature\Companies;

use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 0 POS: perusahaan bisa punya hanya POS, hanya akuntansi, atau keduanya,
 * dengan langganan dan kuota per produk.
 */
class MultiProductCompanyTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDirectory = storage_path('framework/testing/multi-'.uniqid(getmypid().'_', true));
        File::ensureDirectoryExists($this->tenantDirectory);
        config(['tenant.database_path' => $this->tenantDirectory, 'tenant.driver' => 'sqlite']);
    }

    protected function tearDown(): void
    {
        DB::disconnect('tenant');
        DB::disconnect('pos');
        if ($this->tenantDirectory !== '' && File::isDirectory($this->tenantDirectory)) {
            File::deleteDirectory($this->tenantDirectory);
        }

        parent::tearDown();
    }

    public function test_pos_only_company_has_no_accounting_tenant(): void
    {
        $user = $this->client(pos: true);

        $response = $this->postJson('/api/companies', ['name' => 'Warung Bu Sri', 'product' => 'pos'])
            ->assertCreated()
            ->assertJsonPath('data.products', ['pos'])
            ->assertJsonPath('data.tenant_database', null);
        $companyId = (int) $response->json('data.id');
        $headers = ['X-Company-ID' => (string) $companyId];

        $this->assertNull(TenantDatabase::query()->where('company_id', $companyId)->first());
        $this->assertNotNull(TenantDatabase::forProduct('pos')->where('company_id', $companyId)->first());

        $this->getJson('/api/companies')->assertOk()->assertJsonPath('data.0.products', ['pos']);
        $this->postJson('/api/companies/select', ['company_id' => $companyId])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRODUCT_NOT_ACTIVE');
        $this->getJson('/api/setup/status', $headers)->assertStatus(422);
        $this->getJson('/api/pos/bootstrap', $headers)->assertOk()->assertJsonPath('data.company.id', $companyId);

        $this->assertSame(SubscriptionService::STATE_NONE, app(SubscriptionService::class)->stateFor($user));
    }

    public function test_pos_company_requires_pos_subscription_and_respects_pos_quota(): void
    {
        $this->client(accounting: true);
        $this->postJson('/api/companies', ['name' => 'Toko Tanpa Paket', 'product' => 'pos'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->client(pos: true);
        $this->postJson('/api/companies', ['name' => 'Toko Satu', 'product' => 'pos'])->assertCreated();
        // Paket POS bawaan = 1 toko.
        $this->postJson('/api/companies', ['name' => 'Toko Dua', 'product' => 'pos'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'COMPANY_QUOTA_EXCEEDED');
    }

    public function test_pos_only_company_does_not_use_accounting_quota(): void
    {
        $this->client(accounting: true, pos: true);

        $this->postJson('/api/companies', ['name' => 'Warung Kasir', 'product' => 'pos'])->assertCreated();
        $this->postJson('/api/companies', ['name' => 'PT Pembukuan'])
            ->assertCreated()
            ->assertJsonPath('data.products', ['accounting']);
    }

    public function test_owner_can_activate_accounting_for_pos_company_with_accounting_subscription(): void
    {
        $user = $this->client(pos: true);
        $companyId = (int) $this->postJson('/api/companies', ['name' => 'Koperasi Sekolah', 'product' => 'pos'])
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/companies/{$companyId}/products/accounting/activate")
            ->assertStatus(403)
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->activeSubscriptionFor($user);

        $this->postJson("/api/companies/{$companyId}/products/accounting/activate")
            ->assertOk()
            ->assertJsonPath('data.products', ['accounting', 'pos']);
        $this->postJson('/api/companies/select', ['company_id' => $companyId])->assertOk();

        $this->postJson("/api/companies/{$companyId}/products/unknown/activate")->assertNotFound();
    }

    public function test_accounting_company_can_activate_pos_and_both_tenants_coexist(): void
    {
        $this->client(accounting: true, pos: true);
        $companyId = (int) $this->postJson('/api/companies', ['name' => 'PT Dua Produk'])->assertCreated()->json('data.id');
        $headers = ['X-Company-ID' => (string) $companyId];

        $this->postJson("/api/companies/{$companyId}/products/pos/activate")
            ->assertOk()
            ->assertJsonPath('data.products', ['accounting', 'pos']);

        // Dua tenant terbuka di koneksi berbeda dalam satu proses.
        $this->getJson('/api/setup/status', $headers)->assertOk();
        $this->getJson('/api/pos/bootstrap', $headers)->assertOk();
        $this->assertNotSame(
            DB::connection('tenant')->getDatabaseName(),
            DB::connection('pos')->getDatabaseName(),
        );
    }

    private function client(bool $accounting = false, bool $pos = false): User
    {
        $user = User::factory()->create(['status' => 'active']);

        if ($accounting) {
            $this->activeSubscriptionFor($user);
        }
        if ($pos) {
            Subscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => Plan::query()->where('code', Plan::POS_CODE)->value('id'),
                'product' => 'pos',
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonth(),
                'billing_cycle' => 'monthly',
                'price' => 0,
            ]);
        }

        Sanctum::actingAs($user, ['*']);

        return $user;
    }
}
