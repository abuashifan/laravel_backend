<?php

namespace Tests\Feature\Pos;

use App\Shared\Auth\TokenAbility;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Fase 3a: stok masuk & pemasok, retur, stok opname, kunci idempoten penjualan. */
class PosOperationsTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantDirectory = '';

    /** @var array<string,string> */
    private array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDirectory = storage_path('framework/testing/pos-ops-'.uniqid(getmypid().'_', true));
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

    public function test_purchase_updates_average_cost_and_supplier_payable(): void
    {
        $productId = $this->product(price: 5000, cost: 2000, stock: 10);
        $serviceId = $this->postJson('/api/pos/products', ['name' => 'Es batu', 'price' => 1000, 'cost' => 300], $this->headers)->json('data.id');

        // Belum lunas tanpa pemasok ditolak.
        $this->postJson('/api/pos/purchases', [
            'paid_amount' => 0,
            'lines' => [['product_id' => $productId, 'quantity' => 1, 'unit_cost' => 3000]],
        ], $this->headers)->assertStatus(422);

        $supplierId = $this->postJson('/api/pos/suppliers', ['name' => 'Toko Grosir Jaya', 'phone' => '0812'], $this->headers)
            ->assertCreated()->json('data.id');

        $purchase = $this->postJson('/api/pos/purchases', [
            'supplier_id' => $supplierId,
            'paid_amount' => 10000,
            'payment_method' => 'cash',
            'lines' => [
                ['product_id' => $productId, 'quantity' => 10, 'unit_cost' => 3000],
                ['product_id' => $serviceId, 'quantity' => 5, 'unit_cost' => 400],
            ],
        ], $this->headers)->assertCreated()
            ->assertJsonPath('data.total', 32000)
            ->assertJsonPath('data.payable_amount', 22000);
        $this->assertStringStartsWith('SM-', $purchase->json('data.number'));

        // (10 x 2000 + 10 x 3000) / 20 = 2500; produk tanpa stok memakai harga beli terakhir.
        $this->getJson("/api/pos/products/{$productId}", $this->headers)->assertJsonPath('data.stock', 20)->assertJsonPath('data.cost', 2500);
        $this->getJson("/api/pos/products/{$serviceId}", $this->headers)->assertJsonPath('data.stock', 0)->assertJsonPath('data.cost', 400);

        $this->getJson("/api/pos/suppliers/{$supplierId}", $this->headers)
            ->assertOk()->assertJsonPath('data.payable_balance', 22000)->assertJsonCount(1, 'data.purchases');

        $this->postJson("/api/pos/suppliers/{$supplierId}/payments", ['amount' => 30000, 'method' => 'cash'], $this->headers)->assertStatus(422);
        $this->postJson("/api/pos/suppliers/{$supplierId}/payments", ['amount' => 7000, 'method' => 'transfer'], $this->headers)
            ->assertCreated()->assertJsonPath('data.supplier.payable_balance', 15000);

        // Pemasok yang masih punya hutang tidak bisa dihapus.
        $this->deleteJson("/api/pos/suppliers/{$supplierId}", [], $this->headers)->assertStatus(422);
    }

    public function test_partial_return_refunds_cash_and_restocks(): void
    {
        $productId = $this->product(price: 5000, cost: 3000, stock: 10);
        $this->postJson('/api/pos/shifts', ['opening_cash' => 50000], $this->headers)->assertCreated();

        // 4 x 5000 = 20000, diskon total 2000 -> 18000 (faktor 0,9).
        $sale = $this->postJson('/api/pos/sales', [
            'payment_method' => 'cash', 'paid_amount' => 20000, 'discount_amount' => 2000,
            'lines' => [['product_id' => $productId, 'quantity' => 4]],
        ], $this->headers)->assertCreated();
        $saleId = $sale->json('data.id');
        $lineId = $sale->json('data.lines.0.id');

        $return = $this->postJson("/api/pos/sales/{$saleId}/returns", [
            'reason' => 'Kemasan sobek',
            'lines' => [['sale_line_id' => $lineId, 'quantity' => 2]],
        ], $this->headers)->assertCreated()
            ->assertJsonPath('data.total', 9000)
            ->assertJsonPath('data.refund_method', 'cash');
        $this->assertStringStartsWith('RT-', $return->json('data.number'));
        $this->getJson("/api/pos/products/{$productId}", $this->headers)->assertJsonPath('data.stock', 8);

        // Melebihi sisa ditolak; barang rusak tidak kembali ke stok.
        $this->postJson("/api/pos/sales/{$saleId}/returns", ['lines' => [['sale_line_id' => $lineId, 'quantity' => 3]]], $this->headers)->assertStatus(422);
        $this->postJson("/api/pos/sales/{$saleId}/returns", [
            'lines' => [['sale_line_id' => $lineId, 'quantity' => 2, 'restock' => false]],
        ], $this->headers)->assertCreated()->assertJsonPath('data.total', 9000);
        $this->getJson("/api/pos/products/{$productId}", $this->headers)->assertJsonPath('data.stock', 8);

        $this->getJson("/api/pos/sales/{$saleId}", $this->headers)
            ->assertOk()->assertJsonCount(2, 'data.returns')->assertJsonPath("data.returnable.{$lineId}", 0);

        // Transaksi yang sudah diretur tidak bisa dibatalkan.
        $this->postJson("/api/pos/sales/{$saleId}/void", ['reason' => 'x'], $this->headers)->assertStatus(422);

        // Laci: 50.000 + 18.000 - 9.000 - 9.000.
        $this->getJson('/api/pos/shifts/current', $this->headers)
            ->assertJsonPath('data.summary.cash_refunds', 18000)
            ->assertJsonPath('data.summary.expected_cash', 50000);

        // Laba: pendapatan 0; HPP 4 x 3000 dikurangi 2 x 3000 yang kembali ke stok.
        $this->getJson('/api/pos/reports/summary', $this->headers)
            ->assertJsonPath('data.returns_total', 18000)
            ->assertJsonPath('data.net_sales', 0)
            ->assertJsonPath('data.cost_total', 6000)
            ->assertJsonPath('data.gross_profit', -6000);
        $this->getJson('/api/pos/returns', $this->headers)->assertOk()->assertJsonPath('data.total', 2);
    }

    public function test_credit_sale_return_reduces_customer_credit(): void
    {
        $productId = $this->product(price: 5000, cost: 3000, stock: 10);
        $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $this->headers)->assertCreated();
        $customerId = $this->postJson('/api/pos/customers', ['name' => 'Bu Ani'], $this->headers)->json('data.id');

        $sale = $this->postJson('/api/pos/sales', [
            'payment_method' => 'credit', 'paid_amount' => 0, 'customer_id' => $customerId,
            'lines' => [['product_id' => $productId, 'quantity' => 2]],
        ], $this->headers)->assertCreated();

        $this->postJson('/api/pos/sales/'.$sale->json('data.id').'/returns', [
            'lines' => [['sale_line_id' => $sale->json('data.lines.0.id'), 'quantity' => 1]],
        ], $this->headers)->assertCreated()->assertJsonPath('data.refund_method', 'credit');

        $this->getJson("/api/pos/customers/{$customerId}", $this->headers)->assertJsonPath('data.credit_balance', 5000);
        $this->getJson('/api/pos/shifts/current', $this->headers)->assertJsonPath('data.summary.expected_cash', 0);
    }

    public function test_stock_count_records_difference(): void
    {
        $productId = $this->product(price: 5000, cost: 2000, stock: 10);
        $untrackedId = $this->postJson('/api/pos/products', ['name' => 'Jasa', 'price' => 1000], $this->headers)->json('data.id');

        $this->postJson('/api/pos/stock-counts', ['lines' => [['product_id' => $untrackedId, 'counted_quantity' => 1]]], $this->headers)->assertStatus(422);

        $count = $this->postJson('/api/pos/stock-counts', [
            'notes' => 'Opname akhir bulan',
            'lines' => [['product_id' => $productId, 'counted_quantity' => 7]],
        ], $this->headers)->assertCreated()
            ->assertJsonPath('data.difference_value', -6000)
            ->assertJsonPath('data.lines.0.system_quantity', 10)
            ->assertJsonPath('data.lines.0.difference', -3);
        $this->assertStringStartsWith('SO-', $count->json('data.number'));

        $this->getJson("/api/pos/products/{$productId}", $this->headers)->assertJsonPath('data.stock', 7);
        $this->getJson("/api/pos/products/{$productId}/stock-movements", $this->headers)->assertJsonPath('data.data.0.type', 'count');
        $this->getJson('/api/pos/stock-counts', $this->headers)->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_sale_with_same_idempotency_key_is_saved_once(): void
    {
        $productId = $this->product(price: 5000, cost: 2000, stock: 10);
        $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $this->headers)->assertCreated();
        $payload = [
            'payment_method' => 'qris', 'idempotency_key' => 'kasir-1-abc',
            'lines' => [['product_id' => $productId, 'quantity' => 1]],
        ];

        $first = $this->postJson('/api/pos/sales', $payload, $this->headers)->assertCreated()->json('data.id');
        $second = $this->postJson('/api/pos/sales', $payload, $this->headers)->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->getJson("/api/pos/products/{$productId}", $this->headers)->assertJsonPath('data.stock', 9);
    }

    public function test_wholesale_price_tiers_tax_and_rounding(): void
    {
        $productId = $this->postJson('/api/pos/products', [
            'name' => 'Air mineral', 'price' => 5000, 'cost' => 3000,
            'price_tiers' => [
                ['min_quantity' => 10, 'price' => 4500],
                ['min_quantity' => 5, 'price' => 4800],
            ],
        ], $this->headers)->assertCreated()
            ->assertJsonPath('data.price_tiers.0.min_quantity', 5)
            ->assertJsonPath('data.price_tiers.1.price', 4500)
            ->json('data.id');
        $this->postJson('/api/pos/products', ['name' => 'X', 'price' => 1, 'price_tiers' => [['min_quantity' => 1, 'price' => 1]]], $this->headers)
            ->assertStatus(422);
        $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $this->headers)->assertCreated();

        // Tanpa pajak & pembulatan: 6 x harga grosir 4.800.
        $this->postJson('/api/pos/sales', ['payment_method' => 'qris', 'lines' => [['product_id' => $productId, 'quantity' => 6]]], $this->headers)
            ->assertCreated()->assertJsonPath('data.lines.0.unit_price', 4800)->assertJsonPath('data.total', 28800);

        // PPN 11% ditambahkan, dibulatkan ke 100 terdekat: 28.800 + 3.168 = 31.968 -> 32.000.
        $this->patchJson('/api/pos/settings', ['tax_mode' => 'exclusive', 'tax_rate' => 11, 'rounding_unit' => '100', 'rounding_mode' => 'nearest'], $this->headers)
            ->assertOk()->assertJsonPath('data.tax_mode', 'exclusive');
        $this->postJson('/api/pos/sales', ['payment_method' => 'cash', 'paid_amount' => 32000, 'lines' => [['product_id' => $productId, 'quantity' => 6]]], $this->headers)
            ->assertCreated()
            ->assertJsonPath('data.tax_amount', 3168)
            ->assertJsonPath('data.rounding_amount', 32)
            ->assertJsonPath('data.total', 32000);

        // PPN sudah termasuk harga, diskon per baris, bulat ke bawah 500:
        // 2 x 5.000 - 250 = 9.750 (pajak di dalamnya 966) -> 9.500.
        $this->patchJson('/api/pos/settings', ['tax_mode' => 'inclusive', 'rounding_unit' => '500', 'rounding_mode' => 'down'], $this->headers)->assertOk();
        $this->postJson('/api/pos/sales', [
            'payment_method' => 'transfer',
            'lines' => [['product_id' => $productId, 'quantity' => 2, 'discount_amount' => 250]],
        ], $this->headers)->assertCreated()
            ->assertJsonPath('data.tax_inclusive', true)
            ->assertJsonPath('data.tax_amount', 966)
            ->assertJsonPath('data.rounding_amount', -250)
            ->assertJsonPath('data.total', 9500);

        // Laporan: pajak dipisah dari pendapatan bersih.
        $this->getJson('/api/pos/reports/summary', $this->headers)
            ->assertJsonPath('data.sales_total', 70300)
            ->assertJsonPath('data.tax_total', 4134)
            ->assertJsonPath('data.net_sales', 66166);
    }

    public function test_store_day_follows_store_timezone(): void
    {
        $productId = $this->product(price: 5000, cost: 2000, stock: 10);
        $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $this->headers)->assertCreated();

        // 18.30 UTC tanggal 6 = 01.30 WIB tanggal 7.
        $this->travelTo(CarbonImmutable::parse('2026-11-06 18:30:00', 'UTC'));
        $number = $this->postJson('/api/pos/sales', ['payment_method' => 'qris', 'lines' => [['product_id' => $productId, 'quantity' => 1]]], $this->headers)
            ->assertCreated()->json('data.number');
        $this->assertStringStartsWith('PJ-261107-', $number);

        $this->getJson('/api/pos/sales?date_from=2026-11-07&date_to=2026-11-07', $this->headers)->assertJsonPath('data.total', 1);
        $this->getJson('/api/pos/sales?date_from=2026-11-06&date_to=2026-11-06', $this->headers)->assertJsonPath('data.total', 0);
        $this->getJson('/api/pos/reports/summary', $this->headers)
            ->assertJsonPath('data.date_from', '2026-11-07')
            ->assertJsonPath('data.sales_count', 1)
            ->assertJsonPath('data.by_day.0.date', '2026-11-07');

        // Toko WIT (UTC+9): tetap tanggal 7.
        $this->patchJson('/api/pos/settings', ['timezone' => 'Asia/Jayapura'], $this->headers)->assertOk();
        $this->getJson('/api/pos/sales?date_from=2026-11-07&date_to=2026-11-07', $this->headers)->assertJsonPath('data.total', 1);
        $this->patchJson('/api/pos/settings', ['timezone' => 'Europe/London'], $this->headers)->assertStatus(422);
    }

    private function product(float $price, float $cost, float $stock): int
    {
        return (int) $this->postJson('/api/pos/products', [
            'name' => 'Gula 1kg', 'price' => $price, 'cost' => $cost, 'track_stock' => true, 'initial_stock' => $stock,
        ], $this->headers)->assertCreated()->json('data.id');
    }

    /** @return array<string,string> */
    private function activeStore(): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Warung Ops '.$user->id,
            'slug' => 'warung-ops-'.$user->id,
            'code' => 'OPS-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
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
