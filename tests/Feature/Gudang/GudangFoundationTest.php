<?php

namespace Tests\Feature\Gudang;

use App\Shared\Models\CompanyUser;
use App\Shared\Models\Permission;
use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
        $this->postJson('/api/gudang/receipts', [], $headers)->assertStatus(402);
    }

    public function test_receipt_is_atomic_and_retry_safe_and_stock_is_visible_by_location(): void
    {
        $this->client(gudang: true);
        $company = $this->postJson('/api/companies', ['name' => 'Gudang Pilot', 'product' => 'gudang'])->assertCreated()->json('data.id');
        $headers = ['X-Company-ID' => (string) $company];
        $sku = ['sku' => 'B01', 'name' => 'Baut', 'unit' => 'kg'];
        $item = $this->postJson('/api/gudang/items', $sku, $headers)->assertCreated()->json('data.id');
        $this->postJson('/api/gudang/items', $sku, $headers)->assertUnprocessable();
        $default = $this->getJson('/api/gudang/locations', $headers)->assertOk()->json('data.0');
        $rack = $this->postJson('/api/gudang/locations', [
            'warehouse_id' => $default['warehouse_id'], 'name' => 'Rak A',
        ], $headers)->assertCreated()->json('data.id');

        $line = ['item_id' => $item, 'location_id' => $rack, 'quantity' => '1.125'];
        $receipt = ['request_key' => 'retry-1', 'source' => 'SJ-1', 'lines' => [$line]];
        $this->postJson('/api/gudang/receipts', array_merge($receipt, ['lines' => [$line, array_merge($line, ['item_id' => 9999])]]), $headers)
            ->assertUnprocessable();
        $this->getJson('/api/gudang/stock', $headers)->assertOk()->assertJsonCount(0, 'data');
        $posted = $this->postJson('/api/gudang/receipts', $receipt, $headers)->assertOk()->json('data');
        $this->assertNotEmpty($posted['public_id']);
        $this->postJson('/api/gudang/receipts', $receipt, $headers)->assertOk()->assertJsonPath('data.public_id', $posted['public_id']);
        $this->postJson('/api/gudang/receipts', array_merge($receipt, ['source' => 'SJ-2']), $headers)->assertUnprocessable();
        $this->getJson('/api/gudang/stock', $headers)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity_milli', 1125)->assertJsonPath('data.0.location_name', 'Rak A');
        $this->getJson("/api/gudang/items/$item/card", $headers)->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.public_id', $posted['public_id']);
        $this->getJson("/api/gudang/items/$item/card?location_id=".$default['id'], $headers)->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_warehouse_documents_partial_delivery_discrepancy_and_approval(): void
    {
        $this->client(gudang: true);
        $company = $this->postJson('/api/companies', ['name' => 'Pilot operasi', 'product' => 'gudang'])->assertCreated()->json('data.id');
        $h = ['X-Company-ID' => (string) $company];
        $item = $this->postJson('/api/gudang/items', ['sku' => 'BK', 'unit' => 'kg', 'name' => 'Besi', 'barcodes' => ['89901'], 'minimum' => '10'], $h)->assertCreated()->json('data.id');
        $origin = $this->getJson('/api/gudang/locations', $h)->assertOk()->json('data.0.id');
        $this->postJson('/api/gudang/warehouses', ['name' => 'Gudang B'], $h)->assertCreated();
        $target = collect($this->getJson('/api/gudang/locations', $h)->json('data'))->firstWhere('warehouse_name', 'Gudang B')['id'];
        $draft = function ($type, $qty, $dest = null) use ($item, $origin, $h) {
            return $this->postJson('/api/gudang/documents', [
                'type' => $type, 'source' => 'Surat uji', 'document_date' => '2026-11-18', 'request_key' => uniqid(),
                'location_id' => $origin, 'target_id' => $dest, 'lines' => [['item_id' => $item, 'quantity' => $qty]],
            ], $h)->assertOk()->json('data.id');
        };
        $act = function ($id, $action, $extra = []) use ($h) {
            return $this->postJson("/api/gudang/documents/$id/actions", $extra + ['action' => $action, 'request_key' => uniqid()], $h);
        };
        $actual = fn ($qty) => ['lines' => [['item_id' => $item, 'quantity' => $qty]]];
        $order = $draft('order', '12.5');
        $this->assertNotNull($order, 'Draf harus menghasilkan ID dokumen.');
        $ordered = $act($order, 'order')->assertOk();
        $this->assertSame('ordered', $ordered->json('data.status'), $ordered->getContent());
        $partial = $act($order, 'receive', $actual('5'))->assertOk();
        $this->assertSame('partial', $partial->json('data.status'), $partial->getContent());
        $act($order, 'receive', $actual('8'))->assertUnprocessable();
        $act($order, 'receive', $actual('7.5'))->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame(2, DB::connection('gudang')->table('gudang_stock_documents')->where('parent_id', $order)->where('type', 'receipt')->count());
        $this->getJson('/api/gudang/documents', $h)->assertOk()->assertJsonPath('data.data.0.parent_number', 'PO-1');
        $issue = $draft('issue', '3');
        $act($issue, 'post', ['request_key' => 'ulang'])->assertOk();
        $act($issue, 'post', ['request_key' => 'ulang'])->assertOk();
        $act($issue, 'cancel')->assertUnprocessable();
        $act($draft('issue', '10'), 'post')->assertUnprocessable();
        $transfer = $draft('transfer', '4', $target);
        $act($transfer, 'ship')->assertOk();
        $act($transfer, 'receive', $actual('3'))->assertOk()->assertJsonPath('data.status', 'disputed');
        $act($transfer, 'resolve')->assertUnprocessable();
        $act($transfer, 'resolve', ['note' => '1 kg dikembalikan ke asal'])->assertOk();
        $adjust = $draft('adjustment', '-1');
        $act($adjust, 'approve')->assertUnprocessable();
        $act($adjust, 'submit')->assertOk();
        $act($adjust, 'approve')->assertOk();
        $stock = collect($this->getJson('/api/gudang/stock', $h)->assertOk()->json('data'));
        $this->assertEquals(5500, $stock->firstWhere('location_id', $origin)['quantity_milli']);
        $this->assertEquals(3000, $stock->firstWhere('location_id', $target)['quantity_milli']);
        $this->getJson('/api/gudang/reports?kind=low&q=89901', $h)->assertOk()->assertJsonPath('data.total', 1);
        $rack = $this->postJson('/api/gudang/locations', ['warehouse_id' => 1, 'parent_id' => $origin, 'name' => 'Bin 2', 'kind' => 'simpan', 'barcode' => 'BIN-02'], $h)->assertCreated()->json('data.id');
        $putaway = $draft('putaway', '0.5', $rack);
        $act($putaway, 'post')->assertOk();
        $act($draft('receipt', '2'), 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $lost = $draft('transfer', '1', $target);
        $act($lost, 'ship')->assertOk();
        $act($lost, 'receive', $actual('0'))->assertOk()->assertJsonPath('data.status', 'disputed');
        $act($lost, 'resolve', ['resolution' => 'loss', 'note' => 'Hilang setelah diperiksa'])->assertOk();
        $this->assertSame(1, DB::connection('gudang')->table('gudang_stock_movements')->where('document_id', $lost)->count());
        $this->getJson('/api/gudang/reports?kind=audit', $h)->assertOk();
        $this->getJson('/api/gudang/reports?kind=movements&export=1', $h)->assertOk()->assertDownload('gudang-movements.xlsx');

        $extra = $draft('transfer','1',$target);
        $act($extra,'ship')->assertOk();
        $act($extra,'receive',$actual('1.5'))->assertOk()->assertJsonPath('data.status','disputed');
        $act($extra,'resolve',['surplus'=>'accept','note'=>'Tambahan setengah kg dikonfirmasi kedua gudang'])->assertOk();
        $this->assertSame(4,DB::connection('gudang')->table('gudang_stock_movements')->where('document_id',$extra)->count());

        $staff = User::factory()->create(['status' => 'active']);
        $member = CompanyUser::query()->create(['company_id' => $company, 'user_id' => $staff->id, 'role' => 'staff', 'status' => 'active', 'joined_at' => now()]);
        $this->assignTestPermissions($member, ['gudang.stock.view', 'gudang.issues.create', 'gudang.stock.adjust']);
        $this->putJson('/api/gudang/members/'.$staff->id, ['warehouses' => [DB::connection('gudang')->table('gudang_locations')->where('id', $origin)->value('warehouse_id')]], $h)->assertOk();
        Sanctum::actingAs($staff, ['*']);
        $scoped = $this->getJson('/api/gudang/stock', $h)->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertNotContains($target, array_column($scoped, 'location_id'));
        $this->getJson('/api/gudang/reports?kind=stock&warehouse_id=2', $h)->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson('/api/gudang/documents', ['type' => 'issue', 'request_key' => 'outscope', 'source' => 'x',
            'document_date' => '2026-11-18', 'location_id' => $target, 'lines' => [['item_id' => $item, 'quantity' => '1']]], $h)->assertForbidden();
        $act($adjust, 'approve')->assertForbidden();
    }

    public function test_outbound_reservation_protects_stock_and_partial_shipment_is_retry_safe(): void
    {
        $this->client(gudang: true);
        $companyId = $this->postJson('/api/companies', ['name' => 'Gudang Pesanan', 'product' => 'gudang'])
            ->assertCreated()->json('data.id');
        $headers = ['X-Company-ID' => (string) $companyId];
        $location = $this->getJson('/api/gudang/locations', $headers)->assertOk()->json('data.0.id');
        $item = $this->postJson('/api/gudang/items', ['sku' => 'PK-BAUT', 'name' => 'Baut', 'unit' => 'PCS'], $headers)
            ->assertCreated()->json('data.id');
        $lines = [['item_id' => $item, 'quantity' => '10']];
        $this->postJson('/api/gudang/receipts', [
            'request_key' => 'opening', 'source' => 'Stok awal',
            'lines' => [['location_id' => $location] + $lines[0]],
        ], $headers)->assertOk();

        $request = [
            'request_key' => 'reserve-stock', 'destination' => 'Pelanggan A', 'location_id' => $location,
            'lines' => [['item_id' => $item, 'quantity' => '8']],
        ];
        $orderId = $this->postJson('/api/gudang/outbound-orders', $request, $headers)->assertOk()->json('data.id');
        $this->postJson('/api/gudang/outbound-orders', $request, $headers)->assertOk()->assertJsonPath('data.id', $orderId);
        $this->postJson('/api/gudang/outbound-orders', array_replace($request, ['request_key' => 'another-order']), $headers)
            ->assertUnprocessable();
        $stock = fn () => $this->getJson('/api/gudang/stock', $headers)->assertOk();
        $stock()->assertJsonPath('data.0.quantity_milli', 10000)->assertJsonPath('data.0.available_milli', 2000);

        $this->putJson('/api/gudang/settings', ['allow_negative' => true], $headers)->assertOk();
        $issueId = $this->postJson('/api/gudang/documents', [
            'type' => 'issue', 'request_key' => 'manual-issue', 'source' => 'Pelanggan B',
            'document_date' => '2026-11-20', 'location_id' => $location,
            'lines' => [['item_id' => $item, 'quantity' => '3']],
        ], $headers)->assertOk()->json('data.id');
        $this->postJson("/api/gudang/documents/{$issueId}/actions", ['action' => 'post', 'request_key' => 'post-manual'], $headers)
            ->assertUnprocessable();

        $action = "/api/gudang/outbound-orders/{$orderId}/actions";
        $list = '/api/gudang/outbound-orders';
        $allocation = $this->getJson($list, $headers)->assertOk()->json('data.data.0.allocations.0.id');
        $pick = ['action' => 'pick', 'request_key' => 'pick-1', 'lines' => [[
            'allocation_id' => $allocation, 'quantity' => '3', 'location_code' => 'LOC-'.$location,
            'item_code' => 'WRONG', 'reason' => 'Belum semua ditemukan.',
        ]]];
        $this->postJson($action, $pick, $headers)->assertUnprocessable();
        $pick['lines'][0]['item_code'] = 'PK-BAUT';
        $this->postJson($action, $pick, $headers)->assertOk();
        $this->postJson($action, $pick, $headers)->assertOk();
        $run = $this->getJson($list, $headers)->assertOk()->assertJsonCount(1, 'data.data.0.runs')
            ->assertJsonPath('data.data.0.runs.0.picks.0.reason', 'Belum semua ditemukan.')
            ->json('data.data.0.runs.0.id');
        $ship = ['action' => 'ship', 'request_key' => 'ship-1', 'run_id' => $run];
        $this->postJson($action, $ship, $headers)->assertUnprocessable();
        $this->postJson($action, ['action' => 'pack', 'request_key' => 'pack-1', 'run_id' => $run,
            'packages' => [['weight_grams' => 1250, 'note' => 'Dus kecil']]], $headers)->assertOk();
        $this->get("/api/gudang/outbound-orders/{$orderId}/runs/{$run}/labels", $headers)
            ->assertOk()->assertDownload("label-kirim-{$orderId}-{$run}.pdf");
        $stock()->assertJsonPath('data.0.quantity_milli', 10000)->assertJsonPath('data.0.reserved_milli', 8000);
        $this->postJson($action, $ship, $headers)->assertOk()->assertJsonPath('data.status', 'partial');
        $this->postJson($action, $ship, $headers)->assertOk()->assertJsonPath('data.status', 'partial');
        $this->getJson($list, $headers)->assertOk()->assertJsonCount(1, 'data.data.0.runs.0.documents')
            ->assertJsonPath('data.data.0.lines.0.shipped_milli', 3000);
        $stock()->assertJsonPath('data.0.quantity_milli', 7000)->assertJsonPath('data.0.reserved_milli', 5000);
        $shipped = $this->getJson($list, $headers)->json('data.data.0.runs.0.documents.0.document_id');
        $this->postJson("/api/gudang/documents/{$shipped}/reverse", ['request_key' => 'reverse-ship', 'note' => 'Salah kirim'], $headers)
            ->assertUnprocessable()->assertJsonFragment(['Surat jalan pesanan keluar tidak dapat dikoreksi. Catat retur pelanggan untuk barang yang kembali.']);

        $cancel = ['action' => 'cancel', 'request_key' => 'cancel-rest', 'note' => 'Pelanggan membatalkan sisa.'];
        $this->postJson($action, $cancel, $headers)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson($action, $cancel, $headers)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $stock()->assertJsonPath('data.0.quantity_milli', 7000)->assertJsonPath('data.0.available_milli', 7000);
        $this->getJson('/api/gudang/outbound-orders', $headers)->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_outbound_fefo_protects_exact_batches_and_posts_one_issue_per_location(): void
    {
        $this->client(gudang: true);
        $company = $this->postJson('/api/companies', ['name' => 'Gudang Batch', 'product' => 'gudang'])->assertCreated()->json('data.id');
        $headers = ['X-Company-ID' => (string) $company];
        $locations = $this->getJson('/api/gudang/locations', $headers)->assertOk()->json('data');
        $first = $locations[0]['id'];
        $warehouse = $locations[0]['warehouse_id'];
        $second = $this->postJson('/api/gudang/locations', ['warehouse_id' => $warehouse, 'name' => 'Rak Kedua'], $headers)
            ->assertCreated()->json('data.id');
        $item = $this->postJson('/api/gudang/items', [
            'sku' => 'BATCH-SIM', 'name' => 'Barang batch', 'unit' => 'PCS', 'batch_enabled' => true,
        ], $headers)->assertCreated()->json('data.id');
        $receive = [];
        foreach ([[$first, 'A', 2], [$first, 'B', 3], [$second, 'C', 4], [$second, 'LATE', 8], [$second, 'EXPIRED', -4]] as [$location, $code, $days]) {
            $lot = $this->postJson('/api/gudang/batches', [
                'item_id' => $item, 'code' => $code, 'expires_at' => now('Asia/Jakarta')->addDays($days)->toDateString(),
            ], $headers)->assertCreated()->json('data.id');
            $receive[] = ['item_id' => $item, 'location_id' => $location, 'batch_id' => $lot, 'quantity' => '2'];
        }
        $this->postJson('/api/gudang/receipts', ['request_key' => 'batch-opening', 'source' => 'Awal', 'lines' => $receive], $headers)->assertOk();
        $order = $this->postJson('/api/gudang/outbound-orders', [
            'request_key' => 'batch-reserve', 'warehouse_id' => $warehouse, 'destination' => 'Pelanggan',
            'lines' => [['item_id' => $item, 'quantity' => '5']],
        ], $headers)->assertOk()->json('data.id');
        $list = '/api/gudang/outbound-orders?open=0';
        $action = "/api/gudang/outbound-orders/{$order}/actions";
        $orderData = $this->getJson($list, $headers)->assertOk()->json('data.data.0');
        $this->assertEqualsCanonicalizing(['A', 'B', 'C'], array_column($orderData['allocations'], 'batch_code'));
        $move = $this->postJson('/api/gudang/documents', [
            'type' => 'issue', 'source' => 'Pengeluaran umum', 'request_key' => 'consume-reserved-lot',
            'location_id' => $second, 'document_date' => now()->toDateString(),
            'lines' => [['item_id' => $item, 'quantity' => '2',
                'batch_id' => DB::connection('gudang')->table('gudang_batches')->where('code', 'C')->value('id')]],
        ], $headers)->assertOk()->json('data.id');
        $this->postJson("/api/gudang/documents/{$move}/actions", ['action' => 'post', 'request_key' => 'post-reserved-lot'], $headers)
            ->assertUnprocessable();
        $picks = array_map(fn (array $row) => [
            'allocation_id' => $row['id'], 'quantity' => (string) ($row['quantity_milli'] / 1000),
            'location_code' => 'LOC-'.$row['location_id'], 'item_code' => 'BATCH-SIM', 'batch_code' => $row['batch_code'],
        ], $orderData['allocations']);
        $this->postJson($action, ['action' => 'pick', 'request_key' => 'batch-pick', 'lines' => $picks], $headers)->assertOk();
        $run = $this->getJson($list, $headers)->assertOk()->json('data.data.0.runs.0.id');
        $this->postJson($action, ['action' => 'pack', 'run_id' => $run, 'request_key' => 'batch-pack',
            'packages' => [['weight_grams' => 2000], ['weight_grams' => 500]]], $headers)->assertOk();
        $this->postJson($action, ['action' => 'ship', 'run_id' => $run, 'request_key' => 'batch-ship'], $headers)
            ->assertOk()->assertJsonPath('data.status', 'shipped');
        $this->getJson($list, $headers)->assertOk()->assertJsonCount(2, 'data.data.0.runs.0.documents');
        $deductions = DB::connection('gudang')->table('gudang_movement_batches as m')->join('gudang_batches as b', 'b.id', '=', 'm.batch_id')
            ->where('m.quantity_milli', '<', 0)->pluck('m.quantity_milli', 'b.code')->map(fn ($value) => (int) $value)->sortKeys()->all();
        $this->assertSame(['A' => -2000, 'B' => -2000, 'C' => -1000], $deductions);
    }

    public function test_putaway_suggestions_rank_same_item_and_preferred_category_and_skip_full_bins(): void
    {
        $this->client(gudang: true);
        $company = $this->postJson('/api/companies', ['name' => 'Gudang Putaway', 'product' => 'gudang'])->assertCreated()->json('data.id');
        $headers = ['X-Company-ID' => (string) $company];
        $warehouse = $this->getJson('/api/gudang/locations', $headers)->assertOk()->json('data.0.warehouse_id');
        $bin = fn (string $name, string $kind = 'simpan') => $this->postJson('/api/gudang/locations', [
            'name' => $name, 'kind' => $kind, 'warehouse_id' => $warehouse,
        ], $headers)->assertCreated()->json('data.id');
        $origin = $bin('Meja terima', 'terima');
        $matching = $bin('Rak barang yang sama');
        $category = $bin('Rak kategori');
        $full = $bin('Rak penuh');
        foreach ([$matching, $category, $full] as $id) {
            $this->patchJson("/api/gudang/locations/{$id}/putaway", [
                'sku_slots' => 1, 'preferred_category' => $id === $category ? 'Baut' : null,
            ], $headers)->assertOk();
        }
        $item = fn (string $sku, string $category) => $this->postJson('/api/gudang/items', [
            'sku' => $sku, 'name' => $sku, 'unit' => 'PCS', 'category' => $category,
        ], $headers)->assertCreated()->json('data.id');
        $target = $item('BAUT-SIM', 'Baut');
        $other = $item('MUR-SIM', 'Mur');
        $this->postJson('/api/gudang/receipts', ['source' => 'Awal', 'request_key' => 'putaway-opening', 'lines' => [
            ['item_id' => $target, 'location_id' => $matching, 'quantity' => '2'],
            ['item_id' => $other, 'location_id' => $full, 'quantity' => '3'],
        ]], $headers)->assertOk();
        $suggestions = $this->getJson("/api/gudang/putaway-suggestions?from={$origin}&items[]={$target}", $headers)->assertOk()->json('data');
        $this->assertEquals([$matching, $category], array_slice(array_column($suggestions, 'id'), 0, 2));
        $this->assertNotContains($full, array_column($suggestions, 'id'));
    }

    public function test_import_preview_and_retry_keep_one_opening_receipt_and_report_bad_rows(): void
    {
        $this->client(gudang: true);
        $company = $this->postJson('/api/companies', ['name' => 'Impor Gudang', 'product' => 'gudang'])->assertCreated()->json('data.id');
        $headers = ['X-Company-ID' => (string) $company];
        $location = $this->getJson('/api/gudang/locations', $headers)->assertOk()->json('data.0.id');
        $csv = "SKU,Nama,Satuan,Barcode,Saldo awal,Tanggal\nB01,Baut,kg,89901,1.125,2026-11-18\nB02,,kg,,,\n";
        $upload = fn ($commit) => $this->postJson('/api/gudang/import', [
            'file' => UploadedFile::fake()->createWithContent('barang.csv', $csv),
            'commit' => $commit, 'location_id' => $location,
        ], $headers);
        $template = $this->get('/api/gudang/import/template', $headers)->assertOk()->streamedContent();
        $sheetPath = $this->tenantDirectory.'/barang.xlsx';
        file_put_contents($sheetPath, $template);
        $book = IOFactory::load($sheetPath);
        $sheet = $book->getSheet(0);
        $this->assertSame('SKU', $sheet->getCell('A1')->getValue());
        $sheet->fromArray(['XL01', 'Barang Excel', 'pcs'], null, 'A2');
        (new Xlsx($book))->save($sheetPath);
        $this->postJson('/api/gudang/import', [
            'file' => new UploadedFile($sheetPath, 'barang.xlsx', null, null, true),
            'commit' => false, 'location_id' => $location,
        ], $headers)->assertOk()->assertJsonPath('data.0.sku', 'XL01')->assertJsonPath('data.0.result', 'Baru');
        $upload(false)->assertOk()->assertJsonPath('data.0.result', 'Baru')->assertJsonPath('data.1.result', 'Gagal');
        $this->getJson('/api/gudang/items', $headers)->assertOk()->assertJsonCount(0, 'data');
        $upload(true)->assertOk()->assertJsonPath('data.1.result', 'Gagal');
        $upload(true)->assertOk()->assertJsonPath('data.0.result', 'Diperbarui');
        $this->getJson('/api/gudang/stock', $headers)->assertOk()->assertJsonPath('data.0.quantity_milli', 1125);
        $this->assertEquals('2026-11-18', DB::connection('gudang')->table('gudang_stock_documents')->value('document_date'));
    }

    public function test_signup_requires_email_code_and_grants_only_one_gudang_trial(): void
    {
        $email = 'gudang-pilot@example.test';
        $signup = ['email' => $email, 'name' => 'Pilot', 'password' => 'test-password', 'code' => 'AABBCCDDEEFF'];
        $this->postJson('/api/gudang/register', $signup)->assertUnprocessable();
        $key = 'gudang-signup:'.hash('sha256', $email);
        Mail::fake();
        $this->postJson('/api/gudang/verification-code', ['email' => $email])->assertOk();
        $this->assertTrue(Cache::has($key));
        for ($i = 0; $i < 2; $i++) {
            Cache::put($key, Hash::make($signup['code']), 60);
            $this->postJson('/api/gudang/register', $signup)->assertOk();
        }
        $user = User::where('email', $email)->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, Subscription::where('user_id', $user->id)->count());
        $this->assertSame('gudang', Subscription::where('user_id', $user->id)->value('product'));
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
