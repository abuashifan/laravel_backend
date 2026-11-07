<?php

namespace Tests\Feature\Pos;

use App\Shared\Auth\TokenAbility;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Fase 4a: laporan rinci, ekspor Excel, dasbor pemilik. */
class PosReportTablesTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantDirectory = '';

    /** @var array<string,string> */
    private array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDirectory = storage_path('framework/testing/pos-report-'.uniqid(getmypid().'_', true));
        File::ensureDirectoryExists($this->tenantDirectory);
        config(['tenant.database_path' => $this->tenantDirectory, 'tenant.driver' => 'sqlite']);

        $this->headers = $this->activeStore();
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

    public function test_detailed_reports_dashboard_and_export(): void
    {
        $categoryId = $this->postJson('/api/pos/categories', ['name' => 'Minuman'], $this->headers)->json('data.id');
        $teh = $this->postJson('/api/pos/products', [
            'name' => 'Teh botol', 'price' => 10000, 'cost' => 6000, 'category_id' => $categoryId,
            'track_stock' => true, 'initial_stock' => 20, 'min_stock' => 5,
        ], $this->headers)->assertCreated()->assertJsonPath('data.min_stock', 5)->json('data.id');
        $roti = $this->postJson('/api/pos/products', ['name' => 'Roti', 'price' => 5000, 'cost' => 2000], $this->headers)->json('data.id');
        $customerId = $this->postJson('/api/pos/customers', ['name' => 'Bu Ani'], $this->headers)->json('data.id');
        $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $this->headers)->assertCreated();

        $cash = $this->postJson('/api/pos/sales', [
            'payment_method' => 'cash', 'paid_amount' => 25000,
            'lines' => [['product_id' => $teh, 'quantity' => 2], ['product_id' => $roti, 'quantity' => 1]],
        ], $this->headers)->assertCreated();
        $this->postJson('/api/pos/sales', [
            'payment_method' => 'credit', 'paid_amount' => 0, 'customer_id' => $customerId,
            'lines' => [['product_id' => $teh, 'quantity' => 1]],
        ], $this->headers)->assertCreated();
        $voided = $this->postJson('/api/pos/sales', [
            'payment_method' => 'cash', 'paid_amount' => 5000, 'lines' => [['product_id' => $roti, 'quantity' => 1]],
        ], $this->headers)->assertCreated()->json('data.id');
        $this->postJson("/api/pos/sales/{$voided}/void", ['reason' => 'Salah ketik'], $this->headers)->assertOk();
        $this->postJson('/api/pos/sales/'.$cash->json('data.id').'/returns', [
            'lines' => [['sale_line_id' => $cash->json('data.lines.0.id'), 'quantity' => 1]],
        ], $this->headers)->assertCreated();

        // Teh: 3 terjual - 1 retur = 2; 20.000 - 12.000 = 8.000 laba.
        $products = collect($this->getJson('/api/pos/reports/products', $this->headers)->assertOk()->json('data.rows'))->keyBy('product_name');
        $this->assertEquals(['quantity' => 2, 'revenue' => 20000, 'cost' => 12000, 'profit' => 8000, 'margin' => 40], array_intersect_key($products['Teh botol'], array_flip(['quantity', 'revenue', 'cost', 'profit', 'margin'])));
        $this->assertEquals(3000, $products['Roti']['profit']);
        $this->getJson('/api/pos/reports/products', $this->headers)->assertJsonPath('data.totals.profit', 11000);

        $categories = collect($this->getJson('/api/pos/reports/categories', $this->headers)->json('data.rows'))->keyBy('category_name');
        $this->assertEquals(20000, $categories['Minuman']['revenue']);
        $this->assertEquals(5000, $categories['Tanpa kategori']['revenue']);

        $this->getJson('/api/pos/reports/cashiers', $this->headers)->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.sales_count', 2)
            ->assertJsonPath('data.rows.0.sales_total', 35000)
            ->assertJsonPath('data.rows.0.voided_count', 1)
            ->assertJsonPath('data.rows.0.returns_total', 10000);
        $this->getJson('/api/pos/reports/hours', $this->headers)->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.sales_count', 2);
        $this->getJson('/api/pos/reports/shifts', $this->headers)->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.status', 'Buka');

        // Stok 18 di atas minimum 5 dan cukup lama -> tidak menipis; minimum 20 -> menipis.
        $this->getJson('/api/pos/reports/low-stock', $this->headers)->assertJsonCount(0, 'data.rows');
        $this->patchJson("/api/pos/products/{$teh}", ['min_stock' => 20], $this->headers)->assertOk();
        $this->getJson('/api/pos/reports/low-stock', $this->headers)
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.stock', 18)
            ->assertJsonPath('data.rows.0.suggested', 2);
        $this->getJson('/api/pos/products?low_stock=1', $this->headers)->assertJsonPath('data.total', 1);

        $this->getJson('/api/pos/reports/credit-aging', $this->headers)
            ->assertJsonPath('data.rows.0.customer_name', 'Bu Ani')
            ->assertJsonPath('data.rows.0.balance', 10000)
            ->assertJsonPath('data.rows.0.age_0_30', 10000)
            ->assertJsonPath('data.totals.balance', 10000);

        $this->getJson('/api/pos/reports/dashboard', $this->headers)->assertOk()
            ->assertJsonPath('data.today.sales_count', 2)
            ->assertJsonPath('data.yesterday.sales_count', 0)
            ->assertJsonPath('data.low_stock_count', 1)
            ->assertJsonCount(1, 'data.open_shifts');

        $this->get('/api/pos/reports/products/export', $this->headers)->assertOk()->assertHeader('content-disposition');
        $this->getJson('/api/pos/reports/unknown', $this->headers)->assertNotFound();
    }

    /** @return array<string,string> */
    private function activeStore(): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Warung Laporan '.$user->id,
            'slug' => 'warung-laporan-'.$user->id,
            'code' => 'LAP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner',
            'full_access' => true, 'is_owner' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => Plan::query()->where('code', Plan::POS_CODE)->value('id'),
            'product' => 'pos',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'billing_cycle' => 'monthly',
            'price' => 0,
        ]);
        Sanctum::actingAs($user, [TokenAbility::CLIENT]);
        $headers = ['X-Company-ID' => (string) $company->id];
        $this->postJson('/api/pos/activate', [], $headers)->assertOk();

        return $headers;
    }
}
