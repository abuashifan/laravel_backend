<?php

namespace Tests\Feature\Gudang;

use App\Shared\Models\Permission;
use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Finlite Gudang Fase 0: produk ketiga di platform. Perusahaan bisa hanya Gudang,
 * dengan tenant, langganan, dan izin sendiri, tanpa menyentuh akuntansi atau Kasir.
 */
class GudangFoundationTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDirectory = storage_path('framework/testing/gudang-'.uniqid(getmypid().'_', true));
        File::ensureDirectoryExists($this->tenantDirectory);
        config(['tenant.database_path' => $this->tenantDirectory, 'tenant.driver' => 'sqlite']);
    }

    protected function tearDown(): void
    {
        DB::disconnect('tenant');
        DB::disconnect('pos');
        DB::disconnect('gudang');
        if ($this->tenantDirectory !== '' && File::isDirectory($this->tenantDirectory)) {
            File::deleteDirectory($this->tenantDirectory);
        }

        parent::tearDown();
    }

    public function test_gudang_plans_and_permissions_exist(): void
    {
        $this->assertSame('gudang', Plan::query()->where('code', Plan::GUDANG_CODE)->value('product'));
        $free = Plan::query()->where('code', Plan::GUDANG_FREE_CODE)->firstOrFail();
        $this->assertSame('gudang', $free->productKey());
        $this->assertSame(100, (int) $free->max_products);
        $this->assertSame(1, (int) $free->max_users);

        $keys = Permission::query()->where('key', 'like', 'gudang.%')->pluck('key')->all();
        $this->assertContains('gudang.items.view', $keys);
        $this->assertContains('gudang.stock.adjust', $keys);
    }

    public function test_gudang_only_company_gets_its_own_tenant_and_no_other_product(): void
    {
        $this->client(gudang: true);

        $response = $this->postJson('/api/companies', ['name' => 'Gudang Maju', 'product' => 'gudang'])
            ->assertCreated()
            ->assertJsonPath('data.products', ['gudang'])
            ->assertJsonPath('data.tenant_database', null);
        $companyId = (int) $response->json('data.id');
        $headers = ['X-Company-ID' => (string) $companyId];

        $this->assertNull(TenantDatabase::query()->where('company_id', $companyId)->first());
        $tenant = TenantDatabase::forProduct('gudang')->where('company_id', $companyId)->firstOrFail();
        $this->assertStringEndsWith('_gudang.sqlite', (string) $tenant->database_path);

        $this->getJson('/api/gudang/bootstrap', $headers)->assertOk()
            ->assertJsonPath('data.company.id', $companyId)
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.subscription.state', 'active')
            ->assertJsonPath('data.subscription.read_only', false);

        // Tenant Gudang bermigrasi di koneksi `gudang`, bukan di koneksi akuntansi.
        $this->assertTrue(Schema::connection('gudang')->hasTable('gudang_settings'));
        $this->assertFalse(Schema::connection('gudang')->hasTable('journal_entries'));

        // Rute Kasir dan akuntansi tertutup untuk perusahaan ini.
        $this->getJson('/api/pos/bootstrap', $headers)->assertStatus(422)->assertJsonPath('code', 'TENANT_DATABASE_NOT_ACTIVE');
        $this->getJson('/api/setup/status', $headers)->assertStatus(422);
    }

    public function test_company_without_gudang_subscription_cannot_create_or_activate(): void
    {
        $this->client(pos: true);
        $this->postJson('/api/companies', ['name' => 'Tanpa Paket', 'product' => 'gudang'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_existing_pos_company_can_activate_gudang_with_subscription(): void
    {
        $user = $this->client(pos: true);
        $companyId = (int) $this->postJson('/api/companies', ['name' => 'Toko Sekaligus Gudang', 'product' => 'pos'])
            ->assertCreated()->json('data.id');
        $headers = ['X-Company-ID' => (string) $companyId];

        $this->getJson('/api/gudang/status', $headers)->assertOk()
            ->assertJsonPath('data.active', false)->assertJsonPath('data.subscribed', false)->assertJsonPath('data.can_activate', true);
        $this->postJson('/api/gudang/activate', [], $headers)->assertStatus(403)->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->subscribe($user, 'gudang');
        $this->getJson('/api/gudang/status', $headers)->assertOk()->assertJsonPath('data.subscribed', true);
        $this->postJson('/api/gudang/activate', [], $headers)->assertOk()->assertJsonPath('data.active', true);
        $this->getJson('/api/companies')->assertOk()->assertJsonPath('data.0.products', ['gudang', 'pos']);
        $this->getJson('/api/gudang/bootstrap', $headers)->assertOk();
    }

    public function test_expired_subscription_makes_gudang_read_only_for_writes(): void
    {
        $user = $this->client(gudang: true);
        $companyId = (int) $this->postJson('/api/companies', ['name' => 'Gudang Habis', 'product' => 'gudang'])->assertCreated()->json('data.id');
        $headers = ['X-Company-ID' => (string) $companyId];

        Subscription::query()->where('user_id', $user->id)->where('product', 'gudang')->update(['starts_at' => now()->subMonths(3), 'ends_at' => now()->subMonths(2)]);

        // Baca tetap boleh. Penolakan tulis (402) dijaga middleware `product.subscription:gudang`,
        // yang sudah diuji generik untuk Kasir; rute tulis Gudang pertama datang di Fase 1.
        $this->getJson('/api/gudang/bootstrap', $headers)->assertOk()
            ->assertJsonPath('data.subscription.read_only', true);
    }

    private function subscribe(User $user, string $product): void
    {
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => Plan::query()->where('code', $product === 'gudang' ? Plan::GUDANG_CODE : Plan::POS_CODE)->value('id'),
            'product' => $product,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'billing_cycle' => 'monthly',
            'price' => 0,
        ]);
    }

    private function client(bool $pos = false, bool $gudang = false): User
    {
        $user = User::factory()->create(['status' => 'active']);
        if ($pos) {
            $this->subscribe($user, 'pos');
        }
        if ($gudang) {
            $this->subscribe($user, 'gudang');
        }
        Sanctum::actingAs($user, ['*']);

        return $user;
    }
}
