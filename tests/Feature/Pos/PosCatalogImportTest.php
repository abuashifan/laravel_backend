<?php

namespace Tests\Feature\Pos;

use App\Modules\Pos\Services\PosProductImportService;
use App\Shared\Auth\TokenAbility;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Fase 3c: impor/ekspor produk dan foto produk. */
class PosCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $tenantDirectory = '';

    /** @var array<string,string> */
    private array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDirectory = storage_path('framework/testing/pos-import-'.uniqid(getmypid().'_', true));
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

    public function test_import_preview_then_commit_creates_and_updates_products(): void
    {
        $this->headers = $this->activeStore(Plan::POS_CODE);
        $existingId = $this->postJson('/api/pos/products', [
            'name' => 'Gula 1kg', 'sku' => 'GL1', 'price' => 15000, 'cost' => 12000, 'track_stock' => true, 'initial_stock' => 5,
        ], $this->headers)->assertCreated()->json('data.id');

        // CSV gaya Excel Indonesia: titik koma, angka "Rp 3.500".
        $csv = "\u{FEFF}Nama;SKU;Barcode;Kategori;Satuan;Harga jual;Harga modal;Stok\n"
            ."Kopi sachet;KP1;8991234567890;Minuman;sachet;Rp 1.500;1.100;24\n"
            ."Gula 1kg diskon;GL1;;Sembako;;16.000;;99\n"
            ."Teh botol;;;Minuman;botol;;3000;\n"
            .";;;;;;;\n"
            ."Susu kotak;SK1;8991234567890;Minuman;kotak;5000;;\n";

        $preview = $this->postJson('/api/pos/products/import', ['file' => $this->csv($csv)], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.summary', ['create' => 1, 'update' => 1, 'error' => 2]);
        $rows = collect($preview->json('data.rows'))->keyBy('row');
        $this->assertSame('create', $rows[2]['action']);
        $this->assertSame('update', $rows[3]['action']);
        $this->assertNotEmpty($rows[3]['notes']);
        $this->assertSame(['Harga jual wajib diisi untuk produk baru.'], $rows[4]['errors']);
        $this->assertSame('error', $rows[6]['action']);
        $this->getJson('/api/pos/products', $this->headers)->assertJsonPath('data.total', 1);

        $this->postJson('/api/pos/products/import', ['file' => $this->csv($csv), 'commit' => true], $this->headers)
            ->assertOk()->assertJsonPath('data.committed', true);

        $kopi = $this->getJson('/api/pos/products?search=Kopi', $this->headers)->json('data.data.0');
        $this->assertSame(1500, (int) $kopi['price']);
        $this->assertSame(1100, (int) $kopi['cost']);
        $this->assertSame(24, (int) $kopi['stock']);
        $this->assertTrue($kopi['track_stock']);
        $this->assertSame('Minuman', $kopi['category']['name']);

        // Produk lama: nama & harga berubah, stok & modal tetap.
        $this->getJson("/api/pos/products/{$existingId}", $this->headers)
            ->assertJsonPath('data.name', 'Gula 1kg diskon')
            ->assertJsonPath('data.price', 16000)
            ->assertJsonPath('data.cost', 12000)
            ->assertJsonPath('data.stock', 5);
        $this->getJson('/api/pos/products', $this->headers)->assertJsonPath('data.total', 2);
    }

    public function test_import_respects_plan_product_limit_and_rejects_bad_files(): void
    {
        $this->headers = $this->activeStore(Plan::POS_FREE_CODE);
        $lines = "Nama,Harga jual\n";
        for ($i = 1; $i <= 32; $i++) {
            $lines .= "Produk {$i},1000\n";
        }

        $this->postJson('/api/pos/products/import', ['file' => $this->csv($lines), 'commit' => true], $this->headers)
            ->assertOk()->assertJsonPath('data.summary', ['create' => 30, 'update' => 0, 'error' => 2]);
        $this->getJson('/api/pos/products', $this->headers)->assertJsonPath('data.total', 30);

        $this->postJson('/api/pos/products/import', ['file' => $this->csv("Produk,Modal\nA,1\n")], $this->headers)->assertStatus(422);
        $this->postJson('/api/pos/products/import', [
            'file' => UploadedFile::fake()->createWithContent('produk.pdf', 'x'),
        ], $this->headers)->assertStatus(422);
    }

    public function test_export_and_template_download(): void
    {
        $this->headers = $this->activeStore(Plan::POS_CODE);
        $this->postJson('/api/pos/products', ['name' => 'Gula 1kg', 'price' => 15000], $this->headers)->assertCreated();

        $this->get('/api/pos/products/export', $this->headers)->assertOk()
            ->assertHeader('content-disposition');
        $this->get('/api/pos/products/export?template=1', $this->headers)->assertOk();
        $this->assertSame(['Nama', 'SKU', 'Barcode', 'Kategori', 'Satuan', 'Harga jual', 'Harga modal', 'Stok', 'Stok minimum'], array_keys(PosProductImportService::COLUMNS));
    }

    public function test_product_photo_upload_show_and_delete(): void
    {
        $this->headers = $this->activeStore(Plan::POS_CODE);
        $id = $this->postJson('/api/pos/products', ['name' => 'Gula 1kg', 'price' => 15000], $this->headers)->assertCreated()->json('data.id');

        $this->postJson("/api/pos/products/{$id}/photo", [
            'photo' => 'data:image/png;base64,'.base64_encode('bukan gambar'),
            'thumb' => self::PNG,
        ], $this->headers)->assertStatus(422);
        $this->postJson("/api/pos/products/{$id}/photo", ['photo' => 'https://contoh.test/a.png', 'thumb' => self::PNG], $this->headers)->assertStatus(422);

        $this->postJson("/api/pos/products/{$id}/photo", ['photo' => self::PNG, 'thumb' => self::PNG], $this->headers)
            ->assertOk()->assertJsonPath('data.photo_thumb', self::PNG);
        $this->getJson('/api/pos/products', $this->headers)->assertJsonPath('data.data.0.photo_thumb', self::PNG);
        $this->getJson("/api/pos/products/{$id}/photo", $this->headers)
            ->assertOk()->assertJsonPath('data.photo', self::PNG)->assertJsonPath('data.quota_bytes', 50 * 1024 * 1024);

        $this->deleteJson("/api/pos/products/{$id}/photo", [], $this->headers)->assertOk()->assertJsonPath('data.photo_thumb', null);
        $this->getJson("/api/pos/products/{$id}/photo", $this->headers)->assertJsonPath('data.photo', null)->assertJsonPath('data.used_bytes', 0);
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('produk.csv', $content);
    }

    /** @return array<string,string> */
    private function activeStore(string $planCode): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Warung Impor '.$user->id,
            'slug' => 'warung-impor-'.$user->id,
            'code' => 'IMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner',
            'full_access' => true, 'is_owner' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => Plan::query()->where('code', $planCode)->value('id'),
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
