<?php

namespace Tests\Feature\MasterData;

use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\MasterData\Models\Project;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Models\Warehouse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Menguji 9 endpoint daftar Master Data setelah pindah ke SQL lewat
 * `AppliesListQuery` (Fase 6 / list-query-pushdown).
 *
 * Modul ini berbeda dari lima fase sebelumnya dalam tiga hal, dan ketiganya
 * dikunci di sini:
 *
 * 1. Status memakai `is_active` (boolean), bukan `status` (string).
 *    `?status=active|inactive` harus tetap dipetakan ke boolean seperti jalur
 *    in-memory lama. Pengecualiannya `projects` -- lihat test tersendiri.
 * 2. Tidak ada kolom tanggal, jadi `$listDateColumn = ''` dan filter periode
 *    tidak berlaku (bukan berarti mengecualikan semua baris).
 * 3. Urutan default bukan tanggal dan berbeda-beda per modul; urutan lama
 *    wajib tidak berubah.
 */
class MasterDataListQueryTest extends MasterDataTestCase
{
    /**
     * @return array<string,array{0:string,1:class-string,2:string,3:array<int,array<string,mixed>>,4:array<int,string>}>
     */
    public static function masterDataModules(): array
    {
        // [uri, model, kolom yang dicari, 3 baris seed, urutan default yang diharapkan]
        return [
            'chart-of-accounts' => [
                '/api/master-data/chart-of-accounts', ChartOfAccount::class, 'account_name',
                [
                    ['account_code' => '1-1000', 'account_name' => 'Kas Besar', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_active' => true],
                    ['account_code' => '1-2000', 'account_name' => 'Bank Mandiri', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_active' => true],
                    ['account_code' => '1-3000', 'account_name' => 'Piutang Usaha', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_active' => false],
                ],
                ['1-1000', '1-2000', '1-3000'],
            ],
            'contacts' => [
                '/api/master-data/contacts', Contact::class, 'name',
                [
                    ['contact_code' => 'C-001', 'name' => 'Andi Wijaya', 'is_active' => true],
                    ['contact_code' => 'C-002', 'name' => 'Budi Santoso', 'is_active' => true],
                    ['contact_code' => 'C-003', 'name' => 'Citra Dewi', 'is_active' => false],
                ],
                ['C-001', 'C-002', 'C-003'],
            ],
            'units' => [
                '/api/master-data/units', Unit::class, 'name',
                [
                    ['code' => 'U-001', 'name' => 'Ampere', 'is_active' => true],
                    ['code' => 'U-002', 'name' => 'Buah', 'is_active' => true],
                    ['code' => 'U-003', 'name' => 'Cawan', 'is_active' => false],
                ],
                ['U-001', 'U-002', 'U-003'],
            ],
            'departments' => [
                '/api/master-data/departments', Department::class, 'name',
                [
                    ['code' => 'D-001', 'name' => 'Akuntansi', 'is_active' => true],
                    ['code' => 'D-002', 'name' => 'Bengkel', 'is_active' => true],
                    ['code' => 'D-003', 'name' => 'Cabang', 'is_active' => false],
                ],
                ['D-001', 'D-002', 'D-003'],
            ],
            'product-categories' => [
                '/api/master-data/product-categories', ProductCategory::class, 'name',
                [
                    ['name' => 'Aksesoris', 'is_active' => true],
                    ['name' => 'Bahan Baku', 'is_active' => true],
                    ['name' => 'Cetakan', 'is_active' => false],
                ],
                ['Aksesoris', 'Bahan Baku', 'Cetakan'],
            ],
        ];
    }

    /**
     * Seed 3 baris lalu kembalikan headers. Dua baris aktif, satu nonaktif --
     * cukup untuk menguji pemetaan status boolean.
     *
     * @param  class-string  $model
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,string>
     */
    private function seedRows(string $model, array $rows): array
    {
        $ctx = $this->setUpTenant();
        foreach ($rows as $row) {
            $model::query()->create($row);
        }

        return $ctx['headers'];
    }

    /**
     * @param  class-string  $model
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $expectedOrder
     */
    #[DataProvider('masterDataModules')]
    public function test_search_matches_declared_columns(string $uri, string $model, string $searchColumn, array $rows, array $expectedOrder): void
    {
        $headers = $this->seedRows($model, $rows);

        $term = $rows[1][$searchColumn];
        $res = $this->getJson("{$uri}?page=1&per_page=25&search=".urlencode((string) $term), $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath("data.data.0.{$searchColumn}", $term);
    }

    /**
     * Inti perbedaan modul ini: `?status=active` menyaring `is_active = true`,
     * bukan mencari kolom bernama `status` yang memang tidak ada.
     *
     * @param  class-string  $model
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $expectedOrder
     */
    #[DataProvider('masterDataModules')]
    public function test_status_active_maps_to_is_active_boolean(string $uri, string $model, string $searchColumn, array $rows, array $expectedOrder): void
    {
        $headers = $this->seedRows($model, $rows);

        $this->getJson("{$uri}?page=1&per_page=25&status=active", $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.total', 2);

        $this->getJson("{$uri}?page=1&per_page=25&status=inactive", $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1);
    }

    /**
     * Filter `is_active=0/1` yang dikirim frontend hari ini tetap jalan
     * berdampingan dengan `?status=` di atas.
     *
     * @param  class-string  $model
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $expectedOrder
     */
    #[DataProvider('masterDataModules')]
    public function test_is_active_filter_still_works(string $uri, string $model, string $searchColumn, array $rows, array $expectedOrder): void
    {
        $headers = $this->seedRows($model, $rows);

        $this->getJson("{$uri}?page=1&per_page=25&is_active=1", $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.total', 2);

        $this->getJson("{$uri}?page=1&per_page=25&is_active=0", $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1);
    }

    /**
     * Sembilan endpoint yang menerima `is_active`, beserta seed 2 aktif +
     * 1 nonaktif. Terpisah dari `masterDataModules()` karena provider itu hanya
     * memuat lima modul, sementara bug boolean di bawah mengenai sembilan.
     *
     * @return array<string,array{0:string,1:class-string,2:array<int,array<string,mixed>>}>
     */
    public static function booleanStatusModules(): array
    {
        return [
            'chart-of-accounts' => ['/api/master-data/chart-of-accounts', ChartOfAccount::class, [
                ['account_code' => '1-1000', 'account_name' => 'Kas Besar', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_active' => true],
                ['account_code' => '1-2000', 'account_name' => 'Bank Mandiri', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_active' => true],
                ['account_code' => '1-3000', 'account_name' => 'Piutang Usaha', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_active' => false],
            ]],
            'contacts' => ['/api/master-data/contacts', Contact::class, [
                ['contact_code' => 'C-001', 'name' => 'Andi', 'is_active' => true],
                ['contact_code' => 'C-002', 'name' => 'Budi', 'is_active' => true],
                ['contact_code' => 'C-003', 'name' => 'Citra', 'is_active' => false],
            ]],
            'products' => ['/api/master-data/products', Product::class, [
                ['product_code' => 'PR-001', 'product_name' => 'Kopi', 'product_type' => 'service', 'is_active' => true],
                ['product_code' => 'PR-002', 'product_name' => 'Teh', 'product_type' => 'service', 'is_active' => true],
                ['product_code' => 'PR-003', 'product_name' => 'Roti', 'product_type' => 'service', 'is_active' => false],
            ]],
            'product-categories' => ['/api/master-data/product-categories', ProductCategory::class, [
                ['name' => 'Aksesoris', 'is_active' => true],
                ['name' => 'Bahan Baku', 'is_active' => true],
                ['name' => 'Cetakan', 'is_active' => false],
            ]],
            'units' => ['/api/master-data/units', Unit::class, [
                ['code' => 'U-001', 'name' => 'Ampere', 'is_active' => true],
                ['code' => 'U-002', 'name' => 'Buah', 'is_active' => true],
                ['code' => 'U-003', 'name' => 'Cawan', 'is_active' => false],
            ]],
            'warehouses' => ['/api/master-data/warehouses', Warehouse::class, [
                ['code' => 'W-001', 'name' => 'Alpha', 'is_default' => false, 'is_active' => true],
                ['code' => 'W-002', 'name' => 'Bravo', 'is_default' => false, 'is_active' => true],
                ['code' => 'W-003', 'name' => 'Charlie', 'is_default' => false, 'is_active' => false],
            ]],
            'departments' => ['/api/master-data/departments', Department::class, [
                ['code' => 'D-001', 'name' => 'Akuntansi', 'is_active' => true],
                ['code' => 'D-002', 'name' => 'Bengkel', 'is_active' => true],
                ['code' => 'D-003', 'name' => 'Cabang', 'is_active' => false],
            ]],
            'projects' => ['/api/master-data/projects', Project::class, [
                ['code' => 'P-001', 'name' => 'Renovasi', 'status' => 'ongoing', 'is_active' => true],
                ['code' => 'P-002', 'name' => 'Ekspansi', 'status' => 'ongoing', 'is_active' => true],
                ['code' => 'P-003', 'name' => 'Tunda', 'status' => 'ongoing', 'is_active' => false],
            ]],
        ];
    }

    /**
     * `is_active=true|false` -- bentuk yang BENAR-BENAR dikirim browser.
     *
     * Axios menyerialisasi boolean lewat `toString()`, jadi yang sampai ke
     * backend adalah string `"false"`, bukan `0`. `(bool) "false"` di PHP
     * bernilai **true** karena string tak-kosong selalu truthy, sehingga memilih
     * "Nonaktif" justru menampilkan baris aktif.
     *
     * `test_is_active_filter_still_works` di atas memakai `0`/`1` dan lolos:
     * `(bool) "0"` memang `false`. Itulah kenapa bug ini bertahan sampai
     * dilaporkan pemakai. Test ini memakai bentuk yang dikirim browser.
     *
     * Payment terms sengaja tidak diikutkan: migrasi tenant menyeed syarat bayar
     * bawaan, jadi jumlahnya tidak bisa dipastikan lewat `total`. Endpoint itu
     * dikunci oleh `test_payment_term_inactive_filter_excludes_active_rows`.
     *
     * @param  class-string  $model
     * @param  array<int,array<string,mixed>>  $rows
     */
    #[DataProvider('booleanStatusModules')]
    public function test_is_active_accepts_boolean_strings_from_browser(string $uri, string $model, array $rows): void
    {
        $headers = $this->seedRows($model, $rows);

        $this->getJson("{$uri}?page=1&per_page=25&is_active=true", $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.total', 2);

        $inactive = $this->getJson("{$uri}?page=1&per_page=25&is_active=false", $headers);
        $inactive->assertStatus(200)->assertJsonPath('data.total', 1);

        // Bukan hanya jumlahnya -- baris yang kembali memang yang nonaktif.
        $this->assertSame(
            [false],
            collect($inactive->json('data.data'))->pluck('is_active')->map(fn ($v) => (bool) $v)->all(),
        );
    }

    /**
     * Syarat bayar dipisah karena migrasi tenant menyeed baris bawaan yang
     * semuanya aktif -- `total` tidak bisa dipakai, tapi "tidak ada baris aktif
     * yang lolos" tetap bisa dibuktikan.
     */
    public function test_payment_term_inactive_filter_excludes_active_rows(): void
    {
        $ctx = $this->setUpTenant();
        PaymentTerm::query()->create(['code' => 'PT-A', 'name' => 'Alpha 30', 'sort_order' => 1, 'is_active' => true]);
        PaymentTerm::query()->create(['code' => 'PT-B', 'name' => 'Bravo 60', 'sort_order' => 2, 'is_active' => false]);

        $res = $this->getJson('/api/master-data/payment-terms?page=1&per_page=100&is_active=false', $ctx['headers']);
        $res->assertStatus(200);

        $rows = collect($res->json('data.data'));
        $this->assertNotEmpty($rows, 'Filter nonaktif tidak boleh mengosongkan hasil.');
        $this->assertEmpty(
            $rows->filter(fn (array $r) => (bool) $r['is_active']),
            'Baris aktif ikut lolos filter is_active=false.',
        );
        $this->assertContains('PT-B', $rows->pluck('code')->all());
    }

    /**
     * Urutan tampil tiap daftar TIDAK boleh berubah -- ini yang paling mudah
     * bergeser diam-diam saat sort pindah ke trait.
     *
     * @param  class-string  $model
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $expectedOrder
     */
    #[DataProvider('masterDataModules')]
    public function test_default_order_is_unchanged(string $uri, string $model, string $searchColumn, array $rows, array $expectedOrder): void
    {
        $headers = $this->seedRows($model, $rows);

        $res = $this->getJson("{$uri}?page=1&per_page=25", $headers);
        $res->assertStatus(200);
        $key = array_key_first($rows[0]);   // kolom pertama = code/name pengurut
        $this->assertSame($expectedOrder, collect($res->json('data.data'))->pluck($key)->all());
    }

    /**
     * `$listDateColumn` kosong: filter periode diabaikan, BUKAN membuang semua
     * baris. Kalau ini gagal, daftar master data akan tampak kosong begitu ada
     * parameter tanggal nyasar dari UI.
     *
     * @param  class-string  $model
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $expectedOrder
     */
    #[DataProvider('masterDataModules')]
    public function test_date_filter_is_ignored_not_exclusionary(string $uri, string $model, string $searchColumn, array $rows, array $expectedOrder): void
    {
        $headers = $this->seedRows($model, $rows);

        $this->getJson("{$uri}?page=1&per_page=25&date_from=2020-01-01&date_to=2020-12-31", $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.total', 3);
    }

    /**
     * @param  class-string  $model
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $expectedOrder
     */
    #[DataProvider('masterDataModules')]
    public function test_pagination_shape_is_unchanged(string $uri, string $model, string $searchColumn, array $rows, array $expectedOrder): void
    {
        $headers = $this->seedRows($model, $rows);

        $page1 = $this->getJson("{$uri}?page=1&per_page=2", $headers);
        $page1->assertStatus(200);
        $page1->assertJsonPath('data.total', 3);
        $page1->assertJsonPath('data.current_page', 1);
        $page1->assertJsonPath('data.per_page', 2);
        $page1->assertJsonPath('data.last_page', 2);
        $page1->assertJsonPath('data.from', 1);
        $page1->assertJsonPath('data.to', 2);

        $empty = $this->getJson("{$uri}?page=999&per_page=25", $headers);
        $empty->assertJsonPath('data.total', 3);
        $empty->assertJsonPath('data.from', null);
        $empty->assertJsonPath('data.to', null);
    }

    /**
     * @param  class-string  $model
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $expectedOrder
     */
    #[DataProvider('masterDataModules')]
    public function test_without_page_params_returns_unpaginated_list(string $uri, string $model, string $searchColumn, array $rows, array $expectedOrder): void
    {
        $headers = $this->seedRows($model, $rows);

        $res = $this->getJson($uri, $headers);
        $res->assertStatus(200);
        $this->assertCount(3, $res->json('data'));
    }

    // --- Kasus yang tidak seragam, diuji terpisah ---------------------------

    /**
     * `projects` SATU-SATUNYA master data yang punya kolom `status` string
     * sendiri, jadi `?status=` di sana menyaring kolom itu apa adanya --
     * tidak dipetakan ke `is_active` seperti delapan lainnya. Perilaku ini
     * sama dengan jalur in-memory lama, yang memeriksa kunci `status` lebih
     * dulu lalu berhenti.
     */
    public function test_project_status_filters_own_column_not_is_active(): void
    {
        $ctx = $this->setUpTenant();
        Project::query()->create(['code' => 'P-001', 'name' => 'Renovasi', 'status' => 'ongoing', 'is_active' => true]);
        Project::query()->create(['code' => 'P-002', 'name' => 'Ekspansi', 'status' => 'completed', 'is_active' => true]);
        Project::query()->create(['code' => 'P-003', 'name' => 'Tunda', 'status' => 'ongoing', 'is_active' => false]);

        $res = $this->getJson('/api/master-data/projects?page=1&per_page=25&status=ongoing', $ctx['headers']);
        $res->assertStatus(200);
        // Kalau status salah dipetakan ke is_active, hasilnya 2 (yang aktif), bukan 2 yang ongoing.
        $codes = collect($res->json('data.data'))->pluck('code')->sort()->values()->all();
        $this->assertSame(['P-001', 'P-003'], $codes);

        // is_active tetap filter terpisah dan bisa dipakai bersamaan.
        $both = $this->getJson('/api/master-data/projects?page=1&per_page=25&status=ongoing&is_active=1', $ctx['headers']);
        $both->assertJsonPath('data.total', 1);
        $both->assertJsonPath('data.data.0.code', 'P-001');
    }

    /** Gudang default wajib tetap di urutan pertama. */
    public function test_warehouse_default_stays_first(): void
    {
        $ctx = $this->setUpTenant();
        Warehouse::query()->create(['code' => 'W-001', 'name' => 'Alpha', 'is_default' => false, 'is_active' => true]);
        Warehouse::query()->create(['code' => 'W-002', 'name' => 'Zulu', 'is_default' => true, 'is_active' => true]);

        $res = $this->getJson('/api/master-data/warehouses?page=1&per_page=25', $ctx['headers']);
        $res->assertStatus(200);
        $this->assertSame(['Zulu', 'Alpha'], collect($res->json('data.data'))->pluck('name')->all());
    }

    /** Syarat bayar diurut manual lewat sort_order, bukan abjad. */
    public function test_payment_term_keeps_manual_sort_order(): void
    {
        $ctx = $this->setUpTenant();
        PaymentTerm::query()->create(['code' => 'PT-Z', 'name' => 'Zulu 30', 'sort_order' => 1, 'is_active' => true]);
        PaymentTerm::query()->create(['code' => 'PT-A', 'name' => 'Alpha 60', 'sort_order' => 2, 'is_active' => true]);

        $res = $this->getJson('/api/master-data/payment-terms?page=1&per_page=25', $ctx['headers']);
        $res->assertStatus(200);
        // Migrasi tenant sudah menyeed syarat bayar bawaan (COD, Net 7, ...)
        // dengan sort_order lebih besar; cukup periksa dua teratas. Zulu di atas
        // Alpha membuktikan urutannya sort_order, bukan abjad.
        $this->assertSame(['Zulu 30', 'Alpha 60'], collect($res->json('data.data'))->pluck('name')->take(2)->all());
    }

    /**
     * Agregat stok kini dihitung setelah paginasi. Nilainya harus tetap ada
     * dan benar untuk baris yang dikirim -- lihat catatan di
     * `ProductService::list()`.
     */
    public function test_product_stock_aggregate_survives_pagination(): void
    {
        $ctx = $this->setUpTenant();
        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        foreach (['A', 'B', 'C'] as $i => $letter) {
            Product::query()->create([
                'product_code' => 'SKU-'.$letter,
                'product_name' => 'Produk '.$letter,
                'product_type' => 'goods',
                'unit_id' => $unit->id,
                'is_stock_item' => true,
                'is_active' => true,
            ]);
        }

        $res = $this->getJson('/api/master-data/products?page=1&per_page=2', $ctx['headers']);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 3);
        $this->assertCount(2, $res->json('data.data'));
        foreach ($res->json('data.data') as $row) {
            $this->assertArrayHasKey('current_quantity', $row);
            $this->assertSame(0.0, (float) $row['current_quantity']);
        }
    }
}
