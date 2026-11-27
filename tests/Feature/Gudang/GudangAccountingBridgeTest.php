<?php

namespace Tests\Feature\Gudang;

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Product;
use App\Modules\Purchase\Models\GoodsReceipt;
use App\Shared\AccountMapping\AccountMappingKey;
use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantProductService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Purchase\PurchaseTestCase;

/**
 * Finlite Gudang Fase 4b: penerimaan Gudang menjadi Goods Receipt Accounting (nilai dari harga beli
 * Gudang bila ada), penyesuaian menjadi penyesuaian stok Accounting, idempoten, dan kegagalan
 * tidak pernah menggagalkan transaksi gudang.
 */
class GudangAccountingBridgeTest extends PurchaseTestCase
{
    /** @var array<string,string> */
    private array $h = [];

    private int $location = 0;

    private int $vendor = 0;

    private string $gudangDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Pukul 10.00 WIB: tanggal Gudang (WIB) sama dengan tanggal server (UTC).
        $this->travelTo(Carbon::parse('2026-11-27 03:00:00', 'UTC'));
        $this->gudangDirectory = storage_path('framework/testing/gudang-acc-'.uniqid(getmypid().'_', true));
        File::ensureDirectoryExists($this->gudangDirectory);
        config(['tenant.database_path' => $this->gudangDirectory, 'tenant.driver' => 'sqlite']);
    }

    protected function tearDown(): void
    {
        DB::disconnect('gudang');
        if ($this->gudangDirectory !== '' && File::isDirectory($this->gudangDirectory)) {
            File::deleteDirectory($this->gudangDirectory);
        }
        parent::tearDown();
    }

    private function account(string $code, string $type): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code, 'account_name' => 'Akun '.$code, 'account_type' => $type,
            'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit',
            'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false,
        ])->id;
    }

    /** Perusahaan Accounting yang juga mengaktifkan Gudang, dengan pemetaan akun persediaan lengkap. */
    private function companyWithWarehouse(): array
    {
        $ctx = $this->setUpTenant();
        foreach ([
            [AccountMappingKey::INVENTORY_ASSET, 'inventory', $this->account('1400', 'asset')],
            [AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN, 'inventory', $this->account('4900', 'revenue')],
            [AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS, 'inventory', $this->account('5900', 'expense')],
            ['purchase.inventory_interim', 'purchase', $this->account('2150', 'liability')],
        ] as [$key, $module, $account]) {
            AccountMapping::query()->create(['mapping_key' => $key, 'module' => $module, 'account_id' => $account, 'is_active' => true]);
        }
        Product::query()->whereKey($this->defaultStockProductId)->update(['product_code' => 'BAUT', 'product_name' => 'Baut baja']);
        $this->vendor = $this->createVendor(['name' => 'Pemasok Umum']);
        $this->createVendor(['name' => 'CV Baja Jaya']);

        /** @var User $user */
        $user = $ctx['user'];
        Subscription::query()->create([
            'user_id' => $user->id, 'plan_id' => Plan::query()->where('code', Plan::GUDANG_CODE)->value('id'), 'product' => 'gudang',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'billing_cycle' => 'monthly', 'price' => 0,
        ]);
        app(TenantProductService::class)->activate($ctx['company'], 'gudang');
        Sanctum::actingAs($user, ['*']);
        $this->h = $ctx['headers'];
        $this->location = (int) $this->getJson('/api/gudang/locations', $this->h)->assertOk()->json('data.0.id');

        return $ctx;
    }

    private function item(string $sku, string $name): int
    {
        return (int) $this->postJson('/api/gudang/items', ['sku' => $sku, 'name' => $name, 'unit' => 'pcs'], $this->h)->assertCreated()->json('data.id');
    }

    private function receive(int $item, string $quantity, string $source, ?string $cost, string $key): int
    {
        $line = ['item_id' => $item, 'location_id' => $this->location, 'quantity' => $quantity] + ($cost === null ? [] : ['unit_cost' => $cost]);

        return (int) $this->postJson('/api/gudang/receipts', ['request_key' => $key, 'source' => $source, 'lines' => [$line]], $this->h)->assertOk()->json('data.id');
    }

    /** Jalankan pemeriksaan di tenant Accounting (koneksi `tenant`). */
    private function accounting(callable $callback): mixed
    {
        app(TenantConnectionManager::class)->connect(TenantDatabase::query()->where('product', 'accounting')->latest('id')->firstOrFail());

        return $callback();
    }

    public function test_receipts_become_goods_receipts_and_adjustments_become_stock_adjustments_once(): void
    {
        $this->companyWithWarehouse();
        $baut = $this->item('BAUT', 'Baut baja');
        $cat = $this->item('CAT', 'Cat putih');

        $this->getJson('/api/gudang/accounting-bridge', $this->h)->assertOk()->assertJsonPath('data.eligible', true)->assertJsonPath('data.enabled', false);
        $options = $this->getJson('/api/gudang/accounting-bridge/options', $this->h)->assertOk()->json('data');
        $this->assertContains($this->defaultWarehouseId, array_column($options['warehouses'], 'id'));
        $this->assertContains('CV Baja Jaya', array_column($options['vendors'], 'name'));
        $this->putJson('/api/gudang/accounting-bridge', ['enabled' => true], $this->h)->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');

        // Penerimaan sebelum jembatan menyala tidak ikut terkirim (tanggal mulai = hari ini, tapi dokumen kemarin).
        $this->putJson('/api/gudang/accounting-bridge', ['enabled' => true, 'warehouse_id' => $this->defaultWarehouseId, 'vendor_id' => $this->vendor], $this->h)
            ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.start_date', '2026-11-27');

        // Harga beli Gudang (per dus isi 1 = per pcs) dipakai Accounting karena tidak ada PO.
        $receipt = $this->receive($baut, '10', 'CV Baja Jaya', '1500', 'trm-1');
        $row = $this->getJson('/api/gudang/accounting-bridge/dispatches', $this->h)->assertOk()->json('data.data.0');
        $this->assertSame('sent', $row['status'], (string) $row['error']);
        $this->assertStringStartsWith('GR', $row['reference']);
        $this->accounting(function () use ($receipt) {
            $gr = GoodsReceipt::query()->where('source_type', 'gudang_receipt')->where('source_id', $receipt)->with('vendor')->firstOrFail();
            $this->assertSame('received', $gr->status);
            $this->assertSame('CV Baja Jaya', $gr->vendor->name);
            $balance = StockBalance::query()->where('product_id', $this->defaultStockProductId)->where('warehouse_id', $this->defaultWarehouseId)->firstOrFail();
            $this->assertEqualsWithDelta(10, (float) $balance->quantity_on_hand, 0.0001);
            $this->assertEqualsWithDelta(1500, (float) $balance->average_cost, 0.01);
            $this->assertNotNull(StockMovement::query()->where('source_type', 'goods_receipt')->where('source_id', $gr->id)->value('journal_entry_id'));
        });
        $docs = collect($this->getJson('/api/gudang/documents', $this->h)->assertOk()->json('data.data'));
        $this->assertSame('sent', $docs->firstWhere('id', $receipt)['accounting_status']);

        // Barang yang belum ada di Accounting menahan kiriman tanpa menggagalkan penerimaan gudang.
        $this->receive($cat, '4', 'Toko lain', null, 'trm-2');
        $held = $this->getJson('/api/gudang/accounting-bridge/dispatches?status=held', $this->h)->assertOk()->json('data.data.0');
        $this->assertStringContainsString('CAT', $held['error']);
        $this->accounting(fn () => Product::query()->create(['product_code' => 'CAT', 'product_name' => 'Cat putih', 'product_type' => 'goods',
            'unit_id' => $this->defaultUnitId, 'is_stock_item' => true, 'is_active' => true]));
        $this->postJson("/api/gudang/accounting-bridge/dispatches/{$held['id']}/costs", ['lines' => [['item_id' => $cat, 'unit_cost' => '25000']]], $this->h)
            ->assertOk()->assertJsonPath('data.status', 'sent');
        $this->accounting(function () {
            $gr = GoodsReceipt::query()->where('source_number', 'TRM-2')->with('vendor')->firstOrFail();
            // Asal barang tidak dikenal di Accounting: memakai pemasok bawaan.
            $this->assertSame('Pemasok Umum', $gr->vendor->name);
        });
        $this->postJson("/api/gudang/accounting-bridge/dispatches/{$held['id']}/costs", ['lines' => [['item_id' => $cat, 'unit_cost' => '1']]], $this->h)->assertUnprocessable();

        // Penyesuaian -2 yang disetujui menjadi pengurangan stok yang dinilai rata-rata Accounting.
        $adjust = (int) $this->postJson('/api/gudang/documents', ['type' => 'adjustment', 'source' => 'Rusak', 'document_date' => '2026-11-27', 'request_key' => 'adj',
            'location_id' => $this->location, 'lines' => [['item_id' => $baut, 'quantity' => '-2']]], $this->h)->assertOk()->json('data.id');
        $this->postJson("/api/gudang/documents/$adjust/actions", ['action' => 'submit', 'request_key' => 'adj-s'], $this->h)->assertOk();
        $this->postJson("/api/gudang/documents/$adjust/actions", ['action' => 'approve', 'request_key' => 'adj-a'], $this->h)->assertOk();
        $this->accounting(function () use ($adjust) {
            $movement = StockMovement::query()->where('source_type', 'gudang_adjustment')->where('source_id', $adjust)->firstOrFail();
            $this->assertSame('adjustment_out', $movement->movement_type);
            $this->assertEqualsWithDelta(3000, (float) $movement->total_value, 0.01);
            $this->assertEqualsWithDelta(8, (float) StockBalance::query()->where('product_id', $this->defaultStockProductId)->value('quantity_on_hand'), 0.0001);
        });

        // Sinkron ulang tidak menggandakan apa pun.
        $this->postJson('/api/gudang/accounting-bridge/sync', [], $this->h)->assertOk()->assertJsonPath('data.queued', 0);
        $this->accounting(function () {
            $this->assertSame(2, GoodsReceipt::query()->where('source_type', 'gudang_receipt')->count());
            $this->assertSame(1, StockMovement::query()->where('source_type', 'gudang_adjustment')->count());
        });
        $this->getJson('/api/gudang/accounting-bridge', $this->h)->assertOk()->assertJsonPath('data.counts.sent', 3);

        // Dokumen yang sudah tercatat di Accounting tidak bisa dikoreksi diam-diam.
        $this->postJson("/api/gudang/documents/$receipt/reverse", ['request_key' => 'kor', 'note' => 'Salah input'], $this->h)->assertUnprocessable();
    }

    public function test_unsent_receipts_are_cancelled_with_their_correction_and_disconnect_stops_new_sends(): void
    {
        $this->companyWithWarehouse();
        $cat = $this->item('CAT', 'Cat putih');
        $this->putJson('/api/gudang/accounting-bridge', ['enabled' => true, 'warehouse_id' => $this->defaultWarehouseId, 'vendor_id' => $this->vendor], $this->h)->assertOk();
        $receipt = $this->receive($cat, '1', 'Toko', null, 'trm-x');
        $this->getJson('/api/gudang/accounting-bridge/dispatches', $this->h)->assertOk()->assertJsonPath('data.data.0.status', 'held');
        $this->postJson("/api/gudang/documents/$receipt/reverse", ['request_key' => 'kor-x', 'note' => 'Salah barang'], $this->h)->assertOk();
        $this->getJson('/api/gudang/accounting-bridge/dispatches', $this->h)->assertOk()->assertJsonPath('data.total', 0);

        $this->putJson('/api/gudang/accounting-bridge', ['enabled' => false], $this->h)->assertOk()->assertJsonPath('data.enabled', false);
        $this->receive($cat, '1', 'Toko', '100', 'trm-y');
        $this->getJson('/api/gudang/accounting-bridge/dispatches', $this->h)->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson('/api/gudang/accounting-bridge/dispatches/1/retry', [], $this->h)->assertUnprocessable();
    }
}
