<?php

namespace Tests\Feature\MasterData;

use App\Modules\MasterData\Models\ChartOfAccount;
use Illuminate\Testing\TestResponse;

/**
 * Menguji daftar COA yang TERPAGINASI tapi tetap terurut hierarkis.
 *
 * Sebelum ini halaman COA memuat seluruh akun tanpa paginasi, karena paginasi
 * biasa memutus pohon: anak di halaman 2 yang induknya di halaman 1 jadi yatim
 * saat `buildTree()` dijalankan di frontend. Solusinya bukan menghindari
 * paginasi, melainkan meratakan pohon di SQL (recursive CTE) sehingga tiap
 * baris membawa `depth`-nya sendiri -- meniru aplikasi acuan, yang halaman 1
 * berakhir di `1104` dan halaman 2 dimulai dari `1104.01`.
 */
class ChartOfAccountHierarchyListTest extends MasterDataTestCase
{
    private const URI = '/api/master-data/chart-of-accounts';

    /**
     * Struktur uji (kode SENGAJA memuat jebakan prefiks `110` vs `1100`):
     *
     *   110               d0
     *     110.9           d1
     *   1100              d0
     *     1100.1          d1
     *       1100.1.1      d2
     *   1104              d0
     *     1104.01         d1
     *   2100              d0
     *
     * @return array{headers: array<string,string>, ids: array<string,int>}
     */
    private function seedTree(): array
    {
        $ctx = $this->setUpTenant();

        $buat = function (string $code, ?int $parentId, bool $active = true): int {
            return (int) ChartOfAccount::query()->create([
                'account_code' => $code,
                'account_name' => 'Akun '.$code,
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'is_active' => $active,
                'parent_account_id' => $parentId,
            ])->id;
        };

        $ids = [];
        $ids['110'] = $buat('110', null);
        $ids['110.9'] = $buat('110.9', $ids['110']);
        $ids['1100'] = $buat('1100', null);
        $ids['1100.1'] = $buat('1100.1', $ids['1100']);
        $ids['1100.1.1'] = $buat('1100.1.1', $ids['1100.1']);
        $ids['1104'] = $buat('1104', null);
        $ids['1104.01'] = $buat('1104.01', $ids['1104']);
        $ids['2100'] = $buat('2100', null);

        return ['headers' => $ctx['headers'], 'ids' => $ids];
    }

    /** @return array<int,string> */
    private function kodeDari(TestResponse $res): array
    {
        return collect($res->json('data.data'))->pluck('account_code')->all();
    }

    public function test_urutan_pre_order_dan_depth_benar(): void
    {
        ['headers' => $headers] = $this->seedTree();

        $res = $this->getJson(self::URI.'?page=1&per_page=25', $headers);
        $res->assertStatus(200);

        // Pre-order: induk lalu keturunannya, bukan sekadar urut kode.
        $this->assertSame(
            ['110', '110.9', '1100', '1100.1', '1100.1.1', '1104', '1104.01', '2100'],
            $this->kodeDari($res),
        );

        $depthPerKode = collect($res->json('data.data'))->pluck('depth', 'account_code')->all();
        $this->assertSame(0, $depthPerKode['110']);
        $this->assertSame(1, $depthPerKode['110.9']);
        $this->assertSame(2, $depthPerKode['1100.1.1']);
    }

    /**
     * Jebakan prefiks: kalau path memakai titik sebagai pemisah, root `1100`
     * akan menyelip di antara `110` dan anaknya `110.9`. Pemisah `char(1)`
     * mencegahnya. Ini yang paling mudah rusak kalau pemisahnya diubah.
     */
    public function test_kode_berprefiks_sama_tidak_mengacaukan_urutan(): void
    {
        ['headers' => $headers] = $this->seedTree();

        $kode = $this->kodeDari($this->getJson(self::URI.'?page=1&per_page=25', $headers));

        $posisi110 = array_search('110', $kode, true);
        $posisiAnak = array_search('110.9', $kode, true);
        $posisi1100 = array_search('1100', $kode, true);

        $this->assertSame($posisi110 + 1, $posisiAnak, '110.9 harus tepat sesudah induknya 110');
        $this->assertGreaterThan($posisiAnak, $posisi1100, '1100 tidak boleh menyelip sebelum anak dari 110');
    }

    /**
     * Skenario acuan: potongan halaman jatuh di tengah subtree, dan baris
     * pertama halaman berikutnya tetap membawa depth-nya sendiri walau
     * induknya tertinggal di halaman sebelumnya.
     */
    public function test_induk_dan_anak_boleh_terpisah_halaman(): void
    {
        ['headers' => $headers] = $this->seedTree();

        // per_page=6 -> halaman 1 berakhir di `1104` (posisi 6), halaman 2
        // dimulai dari anaknya `1104.01`.
        $kodeHal1 = $this->kodeDari($this->getJson(self::URI.'?page=1&per_page=6', $headers));
        $this->assertSame('1104', $kodeHal1[count($kodeHal1) - 1]);

        $hal2 = $this->getJson(self::URI.'?page=2&per_page=6', $headers);
        $baris = $hal2->json('data.data');
        $this->assertSame('1104.01', $baris[0]['account_code']);
        $this->assertSame(1, $baris[0]['depth'], 'depth harus tetap benar walau induknya di halaman lain');
    }

    public function test_total_mencakup_semua_baris_bukan_hanya_root(): void
    {
        ['headers' => $headers] = $this->seedTree();

        $this->getJson(self::URI.'?page=1&per_page=3', $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.total', 8)
            ->assertJsonPath('data.last_page', 3);
    }

    public function test_search_mengembalikan_daftar_datar_tanpa_depth(): void
    {
        ['headers' => $headers] = $this->seedTree();

        $res = $this->getJson(self::URI.'?page=1&per_page=25&search=1100', $headers);
        $res->assertStatus(200);
        $res->assertJsonMissingPath('data.data.0.depth');
        $this->assertSame(['1100', '1100.1', '1100.1.1'], $this->kodeDari($res));
    }

    public function test_sort_by_kolom_lain_mengabaikan_hierarki(): void
    {
        ['headers' => $headers] = $this->seedTree();

        $res = $this->getJson(self::URI.'?page=1&per_page=25&sort_by=account_code&sort_direction=desc', $headers);
        $res->assertStatus(200);
        $res->assertJsonMissingPath('data.data.0.depth');
        $this->assertSame('2100', $this->kodeDari($res)[0]);
    }

    /** Kontrak AppliesListQuery: tanpa page/per_page kirim semua, tetap hierarkis. */
    public function test_tanpa_page_dan_per_page_tetap_semua_baris_terurut_hierarkis(): void
    {
        ['headers' => $headers] = $this->seedTree();

        $res = $this->getJson(self::URI, $headers);
        $res->assertStatus(200);
        $kode = collect($res->json('data'))->pluck('account_code')->all();
        $this->assertCount(8, $kode);
        $this->assertSame(['110', '110.9', '1100'], array_slice($kode, 0, 3));
    }

    public function test_hierarchy_path_tidak_bocor_ke_json(): void
    {
        ['headers' => $headers] = $this->seedTree();

        $this->getJson(self::URI.'?page=1&per_page=25', $headers)
            ->assertStatus(200)
            ->assertJsonMissingPath('data.data.0.hierarchy_path');
    }

    public function test_filter_tetap_benar_dan_urutan_tetap_hierarkis(): void
    {
        $ctx = $this->setUpTenant();
        $indukId = (int) ChartOfAccount::query()->create([
            'account_code' => '1100', 'account_name' => 'Induk', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'is_active' => true,
        ])->id;
        ChartOfAccount::query()->create([
            'account_code' => '1100.1', 'account_name' => 'Anak Aktif', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'is_active' => true, 'parent_account_id' => $indukId,
        ]);
        ChartOfAccount::query()->create([
            'account_code' => '1100.2', 'account_name' => 'Anak Nonaktif', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'is_active' => false, 'parent_account_id' => $indukId,
        ]);

        $res = $this->getJson(self::URI.'?page=1&per_page=25&is_active=1', $ctx['headers']);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 2);
        $this->assertSame(['1100', '1100.1'], $this->kodeDari($res));
    }

    /**
     * Dialog pemilih akun punya dua kotak filter terpisah (No Akun & Nama
     * Akun) yang harus di-AND-kan. `search` bawaan bersifat OR antar kedua
     * kolom, jadi tidak bisa menggantikan keduanya.
     */
    public function test_filter_kode_dan_nama_dipakai_bersamaan(): void
    {
        $ctx = $this->setUpTenant();
        $headers = $ctx['headers'];

        $buat = function (string $code, string $name): void {
            ChartOfAccount::query()->create([
                'account_code' => $code,
                'account_name' => $name,
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'is_active' => true,
            ]);
        };

        $buat('1101', 'Kas');
        $buat('1101.01', 'Kas - IDR');
        $buat('1102', 'Bank');
        $buat('1102.01', 'Bank BCA - IDR');

        // Hanya kode.
        $byCode = $this->getJson(self::URI.'?page=1&per_page=25&account_code=1101', $headers);
        $byCode->assertStatus(200);
        $this->assertSame(['1101', '1101.01'], $this->kodeDari($byCode));

        // Hanya nama.
        $byName = $this->getJson(self::URI.'?page=1&per_page=25&account_name=Bank', $headers);
        $byName->assertStatus(200);
        $this->assertSame(['1102', '1102.01'], $this->kodeDari($byName));

        // Keduanya = AND, bukan OR: 1101 punya kode cocok tapi nama tidak.
        $both = $this->getJson(self::URI.'?page=1&per_page=25&account_code=1102&account_name=BCA', $headers);
        $both->assertStatus(200);
        $this->assertSame(['1102.01'], $this->kodeDari($both));

        // Tanpa filter: urutan hierarkis tetap seperti semula.
        $none = $this->getJson(self::URI.'?page=1&per_page=25', $headers);
        $this->assertSame(['1101', '1101.01', '1102', '1102.01'], $this->kodeDari($none));
    }

    public function test_siklus_induk_ditolak(): void
    {
        ['headers' => $headers, 'ids' => $ids] = $this->seedTree();

        // 1100 dijadikan anak dari cucunya sendiri -> siklus.
        $this->patchJson(self::URI.'/'.$ids['1100'], [
            'parent_account_id' => $ids['1100.1.1'],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_PARENT_ACCOUNT');
    }

    public function test_kedalaman_melebihi_batas_ditolak(): void
    {
        $ctx = $this->setUpTenant();

        $parentId = null;
        // MAX_DEPTH = 10 -> membuat rantai 10 tingkat masih boleh (depth 0..9).
        for ($i = 0; $i < 10; $i++) {
            $parentId = (int) ChartOfAccount::query()->create([
                'account_code' => 'A'.$i,
                'account_name' => 'Akun '.$i,
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'is_active' => true,
                'parent_account_id' => $parentId,
            ])->id;
        }

        // Tingkat ke-11 lewat API harus ditolak.
        $this->postJson(self::URI, [
            'account_code' => 'A-TERLALU-DALAM',
            'account_name' => 'Terlalu dalam',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'parent_account_id' => $parentId,
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'MAX_ACCOUNT_DEPTH_EXCEEDED');
    }

    /**
     * `postable_only=1` dipakai semua pemilih akun transaksi untuk
     * menyembunyikan akun induk -- akun induk hanya boleh dipakai untuk
     * rekap saldo di laporan, bukan transaksi.
     */
    public function test_postable_only_menyembunyikan_akun_induk(): void
    {
        ['headers' => $headers] = $this->seedTree();

        $res = $this->getJson(self::URI.'?page=1&per_page=25&postable_only=1', $headers);
        $res->assertStatus(200);

        // '110', '1100', '1104' punya anak -> disembunyikan. Sisanya leaf.
        $this->assertSame(
            ['110.9', '1100.1.1', '1104.01', '2100'],
            $this->kodeDari($res),
        );
    }

    /**
     * Sifat cash/bank mengalir turun hierarki: akun induk "Kas" ditandai
     * `is_cash_bank`, akun anak per-lokasi di bawahnya tidak ditandai
     * satu-satu tapi tetap harus terhitung cash/bank. Dikombinasikan dengan
     * `postable_only=1` (dipakai form Cash Receipt dst), hasilnya cuma akun
     * anak -- induknya sendiri sudah tidak postable.
     */
    public function test_is_cash_bank_mewarisi_ke_akun_anak(): void
    {
        $ctx = $this->setUpTenant();
        $headers = $ctx['headers'];

        $kasIndukId = (int) ChartOfAccount::query()->create([
            'account_code' => '1100', 'account_name' => 'Kas', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'is_active' => true, 'is_cash_bank' => true,
        ])->id;
        ChartOfAccount::query()->create([
            'account_code' => '1100.01', 'account_name' => 'Kas Kecil - Wardi', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'is_active' => true, 'is_cash_bank' => false, 'parent_account_id' => $kasIndukId,
        ]);
        ChartOfAccount::query()->create([
            'account_code' => '2000', 'account_name' => 'Bukan Kas', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'is_active' => true, 'is_cash_bank' => false,
        ]);

        // Tanpa postable_only: induk dan anak sama-sama terhitung cash/bank.
        $semua = $this->getJson(self::URI.'?page=1&per_page=25&is_cash_bank=1', $headers);
        $semua->assertStatus(200);
        $this->assertSame(['1100', '1100.01'], $this->kodeDari($semua));

        // Dikombinasi postable_only (dipakai pemilih akun transaksi): induk
        // hilang karena bukan leaf, tersisa akun anak yang sungguhan dipakai.
        $postable = $this->getJson(self::URI.'?page=1&per_page=25&is_cash_bank=1&postable_only=1', $headers);
        $postable->assertStatus(200);
        $this->assertSame(['1100.01'], $this->kodeDari($postable));
    }
}
