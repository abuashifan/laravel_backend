<?php

namespace Tests\Feature\Gudang;

use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Finlite Gudang Fase 4a: surat jalan dikirim ke kotak masuk Kasir, stok toko
 * baru bertambah saat petugas toko mengonfirmasi, dan selisih kembali ke Gudang
 * sebagai pengecualian tanpa mengubah stok siapa pun diam-diam.
 */
class GudangPosBridgeTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantDirectory = '';

    /** @var array<string,string> */
    private array $h = [];

    private int $origin = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDirectory = storage_path('framework/testing/gudang-pos-'.uniqid(getmypid().'_', true));
        File::ensureDirectoryExists($this->tenantDirectory);
        config(['tenant.database_path' => $this->tenantDirectory, 'tenant.driver' => 'sqlite']);
    }

    protected function tearDown(): void
    {
        foreach (['tenant', 'pos', 'gudang'] as $connection) {
            DB::disconnect($connection);
        }
        if ($this->tenantDirectory !== '' && File::isDirectory($this->tenantDirectory)) {
            File::deleteDirectory($this->tenantDirectory);
        }

        parent::tearDown();
    }

    private function subscribe(User $user, string $product): void
    {
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => Plan::query()->where('code', $product === 'gudang' ? Plan::GUDANG_CODE : Plan::POS_CODE)->value('id'),
            'product' => $product, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'billing_cycle' => 'monthly', 'price' => 0,
        ]);
    }

    /** Perusahaan Kasir yang juga mengaktifkan Gudang, dengan satu barang yang sudah ada di kedua sisi. */
    private function storeWithWarehouse(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->subscribe($user, 'pos');
        $this->subscribe($user, 'gudang');
        Sanctum::actingAs($user, ['*']);
        $company = (int) $this->postJson('/api/companies', ['name' => 'Toko dan Gudang', 'product' => 'pos'])->assertCreated()->json('data.id');
        $this->h = ['X-Company-ID' => (string) $company];
        $this->postJson('/api/gudang/activate', [], $this->h)->assertOk();
        $this->origin = (int) $this->getJson('/api/gudang/locations', $this->h)->assertOk()->json('data.0.id');
    }

    private function gudangItem(string $sku, string $name, array $barcodes = []): int
    {
        $id = (int) $this->postJson('/api/gudang/items', ['sku' => $sku, 'name' => $name, 'unit' => 'pcs', 'barcodes' => $barcodes], $this->h)->assertCreated()->json('data.id');
        $this->postJson('/api/gudang/receipts', ['request_key' => 'stok-'.$sku, 'source' => 'Pemasok', 'lines' => [['item_id' => $id, 'location_id' => $this->origin, 'quantity' => '100']]], $this->h)->assertOk();

        return $id;
    }

    /** Surat jalan yang sudah tercatat. @param array<int,string> $quantities item_id => jumlah */
    private function postedIssue(array $quantities, string $key): int
    {
        $id = (int) $this->postJson('/api/gudang/documents', [
            'type' => 'issue', 'source' => 'Toko', 'document_date' => '2026-11-27', 'request_key' => 'draf-'.$key,
            'location_id' => $this->origin,
            'lines' => collect($quantities)->map(fn ($quantity, $item) => ['item_id' => $item, 'quantity' => $quantity])->values()->all(),
        ], $this->h)->assertOk()->json('data.id');
        $this->postJson("/api/gudang/documents/$id/actions", ['action' => 'post', 'request_key' => 'catat-'.$key], $this->h)->assertOk();

        return $id;
    }

    private function stock(int $item): int
    {
        return (int) collect($this->getJson('/api/gudang/stock', $this->h)->assertOk()->json('data'))->where('item_id', $item)->sum('quantity_milli');
    }

    public function test_shipment_reaches_the_store_inbox_and_stock_moves_only_when_the_store_confirms(): void
    {
        $this->storeWithWarehouse();
        $baut = $this->gudangItem('B01', 'Baut', ['899100']);
        $cat = $this->gudangItem('C02', 'Cat putih');
        $kasirBaut = (int) $this->postJson('/api/pos/products', ['name' => 'Baut kasir', 'sku' => 'LAIN', 'barcode' => '899100', 'price' => 1000, 'cost' => 500, 'track_stock' => true, 'initial_stock' => 2], $this->h)->assertCreated()->json('data.id');

        $this->getJson('/api/pos/bootstrap', $this->h)->assertOk()->assertJsonPath('data.gudang_active', true);
        $state = $this->getJson('/api/gudang/pos-bridge', $this->h)->assertOk()->assertJsonPath('data.eligible', true)->assertJsonPath('data.enabled', false)->json('data');
        $this->assertNull($state['reason']);
        $issue = $this->postedIssue([$baut => '3', $cat => '2'], 'a');
        $this->postJson("/api/gudang/pos-bridge/documents/$issue/send", [], $this->h)->assertUnprocessable();
        $this->putJson('/api/gudang/pos-bridge', ['enabled' => true], $this->h)->assertOk()->assertJsonPath('data.enabled', true);

        // Cat belum ada di Kasir: kiriman ditahan, bukan gagal, dan tidak ada yang masuk ke toko.
        $held = $this->postJson("/api/gudang/pos-bridge/documents/$issue/send", [], $this->h)->assertOk()->assertJsonPath('data.status', 'held')->json('data');
        $this->assertStringContainsString('C02', $held['error']);
        $this->getJson('/api/pos/gudang-receipts', $this->h)->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson("/api/gudang/pos-bridge/documents/$issue/send", [], $this->h)->assertOk()->assertJsonPath('data.id', $held['id']);

        // Impor otomatis membuat produk Kasir yang kurang; barcode lebih diutamakan daripada SKU.
        $this->postJson("/api/gudang/pos-bridge/dispatches/{$held['id']}/retry", ['import_missing' => true], $this->h)->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->postJson("/api/gudang/pos-bridge/dispatches/{$held['id']}/retry", [], $this->h)->assertUnprocessable();
        $this->assertSame(1, DB::connection('pos')->table('pos_gudang_receipts')->count());
        $inbox = $this->getJson('/api/pos/gudang-receipts', $this->h)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.status', 'pending')->assertJsonPath('data.data.0.number', 'SJ-1')->json('data.data.0');
        $this->getJson('/api/pos/gudang-receipts/summary', $this->h)->assertOk()->assertJsonPath('data.pending', 1);
        $receipt = $this->getJson('/api/pos/gudang-receipts/'.$inbox['id'], $this->h)->assertOk()->json('data');
        $this->assertSame($kasirBaut, collect($receipt['lines'])->firstWhere('sku', 'B01')['product_id']);

        // Stok belum bergerak di mana pun sebelum toko mengonfirmasi.
        $this->assertSame(2.0, (float) DB::connection('pos')->table('pos_products')->where('id', $kasirBaut)->value('stock'));
        $this->assertSame(97000, $this->stock($baut));

        $lines = fn (string $b, string $c) => collect($receipt['lines'])->map(fn ($line) => ['id' => $line['id'], 'actual_quantity' => $line['sku'] === 'B01' ? $b : $c])->all();
        $this->postJson("/api/pos/gudang-receipts/{$receipt['id']}/receive", ['lines' => array_slice($lines('3', '1'), 0, 1)], $this->h)->assertUnprocessable();
        $this->postJson("/api/pos/gudang-receipts/{$receipt['id']}/receive", ['lines' => $lines('3', '1')], $this->h)->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->assertSame(2.0, (float) DB::connection('pos')->table('pos_products')->where('id', $kasirBaut)->value('stock'));

        $this->postJson("/api/pos/gudang-receipts/{$receipt['id']}/receive", ['lines' => $lines('3', '1'), 'note' => 'Satu kaleng penyok, dikembalikan'], $this->h)
            ->assertOk()->assertJsonPath('data.status', 'discrepancy');
        $this->assertSame(5.0, (float) DB::connection('pos')->table('pos_products')->where('id', $kasirBaut)->value('stock'));
        // Konfirmasi ulang tidak menggandakan stok.
        $this->postJson("/api/pos/gudang-receipts/{$receipt['id']}/receive", ['lines' => $lines('3', '1'), 'note' => 'ulang'], $this->h)->assertOk()->assertJsonPath('data.status', 'discrepancy');
        $this->assertSame(5.0, (float) DB::connection('pos')->table('pos_products')->where('id', $kasirBaut)->value('stock'));
        $this->getJson('/api/pos/gudang-receipts/summary', $this->h)->assertOk()->assertJsonPath('data.pending', 0);

        // Gudang melihat pengecualian; stok Gudang tidak berubah diam-diam.
        $row = $this->getJson('/api/gudang/pos-bridge/dispatches', $this->h)->assertOk()->assertJsonPath('data.data.0.status', 'discrepancy')->json('data.data.0');
        $this->assertSame('Satu kaleng penyok, dikembalikan', $row['receive_note']);
        $this->assertEqualsCanonicalizing([['B01', '3', '3'], ['C02', '2', '1']], collect($row['summary'])->map(fn ($line) => [$line['sku'], $line['expected'], $line['actual']])->all());
        $this->assertSame(97000, $this->stock($baut));
        $this->getJson('/api/gudang/pos-bridge', $this->h)->assertOk()->assertJsonPath('data.counts.discrepancy', 1)->assertJsonPath('data.unresolved', 1);
        $this->postJson("/api/gudang/pos-bridge/dispatches/{$row['id']}/acknowledge", ['note' => ''], $this->h)->assertUnprocessable();
        $this->postJson("/api/gudang/pos-bridge/dispatches/{$row['id']}/acknowledge", ['note' => 'Retur dicatat'], $this->h)->assertOk()->assertJsonPath('data.acknowledge_note', 'Retur dicatat');
        $this->getJson('/api/gudang/pos-bridge', $this->h)->assertOk()->assertJsonPath('data.unresolved', 0);

        // Surat jalan yang sudah di Kasir tidak boleh dikoreksi; daftar dokumen menandainya.
        $this->postJson("/api/gudang/documents/$issue/reverse", ['request_key' => 'kor-1', 'note' => 'Salah'], $this->h)->assertUnprocessable();
        $docs = collect($this->getJson('/api/gudang/documents', $this->h)->assertOk()->json('data.data'));
        $this->assertSame('discrepancy', $docs->firstWhere('id', $issue)['pos_status']);
    }

    public function test_exact_receipt_is_received_and_unsent_shipments_can_be_cancelled_with_their_document(): void
    {
        $this->storeWithWarehouse();
        $baut = $this->gudangItem('B01', 'Baut');
        $this->postJson('/api/pos/products', ['name' => 'Baut', 'sku' => 'B01', 'price' => 1000, 'track_stock' => true], $this->h)->assertCreated();
        $this->putJson('/api/gudang/pos-bridge', ['enabled' => true], $this->h)->assertOk();

        $issue = $this->postedIssue([$baut => '4'], 'pas');
        $this->postJson("/api/gudang/pos-bridge/documents/$issue/send", [], $this->h)->assertOk()->assertJsonPath('data.status', 'delivered');
        $receipt = $this->getJson('/api/pos/gudang-receipts?status=pending', $this->h)->assertOk()->json('data.data.0');
        $detail = $this->getJson('/api/pos/gudang-receipts/'.$receipt['id'], $this->h)->json('data');
        $this->postJson("/api/pos/gudang-receipts/{$receipt['id']}/receive", ['lines' => [['id' => $detail['lines'][0]['id'], 'actual_quantity' => '4']]], $this->h)
            ->assertOk()->assertJsonPath('data.status', 'received');
        $this->getJson('/api/gudang/pos-bridge/dispatches?status=received', $this->h)->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/gudang/pos-bridge/dispatches?status=discrepancy', $this->h)->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson('/api/gudang/pos-bridge/dispatches/1/acknowledge', ['note' => 'x'], $this->h)->assertUnprocessable();

        // Kiriman yang belum sampai ke Kasir ikut dibatalkan saat surat jalannya dikoreksi.
        $stuck = $this->gudangItem('D03', 'Kuas');
        $second = $this->postedIssue([$stuck => '1'], 'tahan');
        $this->postJson("/api/gudang/pos-bridge/documents/$second/send", [], $this->h)->assertOk()->assertJsonPath('data.status', 'held');
        $this->postJson("/api/gudang/documents/$second/reverse", ['request_key' => 'kor-2', 'note' => 'Salah barang'], $this->h)->assertOk();
        $this->assertSame(0, DB::connection('gudang')->table('gudang_pos_dispatches')->where('document_id', $second)->count());
        $this->assertSame(1, DB::connection('pos')->table('pos_gudang_receipts')->count());

        // Memutus jembatan menghentikan kiriman baru, bukan hasil terima yang sudah ada.
        $this->putJson('/api/gudang/pos-bridge', ['enabled' => false], $this->h)->assertOk()->assertJsonPath('data.enabled', false);
        $third = $this->postedIssue([$baut => '1'], 'putus');
        $this->postJson("/api/gudang/pos-bridge/documents/$third/send", [], $this->h)->assertUnprocessable();
        $this->getJson('/api/gudang/pos-bridge/dispatches', $this->h)->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_warehouse_without_the_store_product_cannot_connect_the_bridge(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->subscribe($user, 'gudang');
        Sanctum::actingAs($user, ['*']);
        $company = (int) $this->postJson('/api/companies', ['name' => 'Gudang Saja', 'product' => 'gudang'])->assertCreated()->json('data.id');
        $h = ['X-Company-ID' => (string) $company];

        $this->getJson('/api/gudang/pos-bridge', $h)->assertOk()->assertJsonPath('data.eligible', false)->assertJsonPath('data.enabled', false);
        $this->putJson('/api/gudang/pos-bridge', ['enabled' => true], $h)->assertUnprocessable();
        $this->postJson('/api/gudang/pos-bridge/import-products', [], $h)->assertUnprocessable();
    }
}
