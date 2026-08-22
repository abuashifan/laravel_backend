<?php

namespace Tests\Feature\Journal;

use App\Modules\Journal\Models\JournalEntry;

/**
 * Menguji `GET /api/journals` setelah `JournalEntryService::list()` pindah
 * dari in-memory (`->get()` lalu disaring `listResponse`) ke SQL lewat
 * `AppliesListQuery` (Fase 1 / list-query-pushdown, pilot pertama).
 *
 * Journal dipilih sebagai pilot karena berbeda dari 31 endpoint lain yang
 * masih menyusul: service-nya SUDAH melakukan filter search/status/tanggal
 * manual di SQL sebelum trait ada (lihat git history) -- jadi pencarian
 * journal_number/description sebenarnya SUDAH benar sebelum fase ini, bukan
 * perubahan perilaku. Yang benar-benar berubah: paginasi kini SQL (bukan
 * ->get() lalu slice), dan status comma-separated yang sebelumnya rusak
 * (where('status', 'draft,posted') literal, selalu 0 hasil) sekarang jalan.
 */
class JournalListQueryTest extends JournalTestCase
{
    /** @return array{headers: array<string,string>} */
    private function seedJournals(): array
    {
        $ctx = $this->setUpTenant();

        JournalEntry::query()->create([
            'journal_number' => 'JV-2026-000001',
            'journal_date' => '2026-01-10',
            'description' => 'Pembelian ATK',
            'status' => 'draft',
        ]);
        JournalEntry::query()->create([
            'journal_number' => 'JV-2026-000002',
            'journal_date' => '2026-02-15',
            'description' => 'Setoran modal',
            'status' => 'posted',
        ]);
        JournalEntry::query()->create([
            'journal_number' => 'JV-2026-000003',
            'journal_date' => '2026-03-20',
            'description' => 'Pembayaran listrik',
            'status' => 'posted',
            // source_number diisi nilai yang TIDAK muncul di journal_number/
            // description -- kalau ini ikut kecari, berarti search melebar
            // lagi di luar $listSearchable (regresi).
            'source_number' => 'XYZ-UNIQUE-999',
        ]);
        JournalEntry::query()->create([
            'journal_number' => 'JV-2026-000004',
            'journal_date' => '2026-03-25',
            'description' => 'Dibatalkan',
            'status' => 'void',
        ]);

        return ['headers' => $ctx['headers']];
    }

    /**
     * Jurnal dengan `source_type` beragam, meniru isi daftar Jurnal Umum yang
     * memuat jurnal dari semua modul sekaligus.
     *
     * @return array{headers: array<string,string>}
     */
    private function seedJournalsBySourceType(): array
    {
        $ctx = $this->setUpTenant();

        $sourceTypes = [
            'JV-SRC-000001' => 'manual_journal',
            'JV-SRC-000002' => 'fixed_asset_depreciation',
            'JV-SRC-000003' => 'fixed_asset_depreciation',
            'JV-SRC-000004' => 'sales_invoice',
            'JV-SRC-000005' => 'vendor_bill',
        ];

        foreach ($sourceTypes as $number => $sourceType) {
            JournalEntry::query()->create([
                'journal_number' => $number,
                'journal_date' => '2026-04-01',
                'description' => "Jurnal {$sourceType}",
                'status' => 'posted',
                'source_type' => $sourceType,
                'is_system_generated' => $sourceType !== 'manual_journal',
            ]);
        }

        return ['headers' => $ctx['headers']];
    }

    public function test_source_type_filters_single_value(): void
    {
        ['headers' => $headers] = $this->seedJournalsBySourceType();

        // Skenario yang diminta user: "lihat jurnal depresiasi saja".
        $res = $this->getJson('/api/journals?page=1&per_page=25&source_type=fixed_asset_depreciation', $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 2);
        $numbers = collect($res->json('data.data'))->pluck('journal_number')->sort()->values()->all();
        $this->assertSame(['JV-SRC-000002', 'JV-SRC-000003'], $numbers);
    }

    public function test_source_type_filters_comma_separated_values(): void
    {
        ['headers' => $headers] = $this->seedJournalsBySourceType();

        $res = $this->getJson('/api/journals?page=1&per_page=25&source_type=sales_invoice,vendor_bill', $headers);
        $res->assertStatus(200);
        $numbers = collect($res->json('data.data'))->pluck('journal_number')->sort()->values()->all();
        $this->assertSame(['JV-SRC-000004', 'JV-SRC-000005'], $numbers);
    }

    public function test_source_type_absent_returns_every_type(): void
    {
        ['headers' => $headers] = $this->seedJournalsBySourceType();

        // Tanpa filter, daftar tetap memuat jurnal manual maupun jurnal sistem.
        $res = $this->getJson('/api/journals?page=1&per_page=25', $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 5);

        // String kosong diperlakukan sama dengan tidak mengirim filter.
        $empty = $this->getJson('/api/journals?page=1&per_page=25&source_type=', $headers);
        $empty->assertStatus(200);
        $empty->assertJsonPath('data.total', 5);
    }

    public function test_source_type_combines_with_other_filters(): void
    {
        ['headers' => $headers] = $this->seedJournalsBySourceType();

        // source_type harus meng-AND filter lain, bukan menggantikannya.
        $res = $this->getJson(
            '/api/journals?page=1&per_page=25&source_type=fixed_asset_depreciation&search=000003',
            $headers,
        );
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath('data.data.0.journal_number', 'JV-SRC-000003');

        $none = $this->getJson(
            '/api/journals?page=1&per_page=25&source_type=fixed_asset_depreciation&status=draft',
            $headers,
        );
        $none->assertStatus(200);
        $none->assertJsonPath('data.total', 0);
    }

    public function test_unknown_source_type_returns_empty_list(): void
    {
        ['headers' => $headers] = $this->seedJournalsBySourceType();

        $res = $this->getJson('/api/journals?page=1&per_page=25&source_type=tidak_ada', $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 0);
    }

    public function test_search_matches_journal_number(): void
    {
        ['headers' => $headers] = $this->seedJournals();

        $res = $this->getJson('/api/journals?page=1&per_page=25&search=000002', $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath('data.data.0.journal_number', 'JV-2026-000002');
    }

    public function test_search_matches_description(): void
    {
        ['headers' => $headers] = $this->seedJournals();

        $res = $this->getJson('/api/journals?page=1&per_page=25&search=listrik', $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath('data.data.0.journal_number', 'JV-2026-000003');
    }

    public function test_search_does_not_match_undeclared_columns(): void
    {
        ['headers' => $headers] = $this->seedJournals();

        // XYZ-UNIQUE-999 cuma ada di source_number, bukan journal_number/
        // description -- harus 0 hasil, bukan 1.
        $res = $this->getJson('/api/journals?page=1&per_page=25&search=XYZ-UNIQUE-999', $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 0);
    }

    public function test_status_filters_correctly(): void
    {
        ['headers' => $headers] = $this->seedJournals();

        // Default (tanpa status): void tetap tersembunyi via include_void.
        $res = $this->getJson('/api/journals?page=1&per_page=25', $headers);
        $res->assertJsonPath('data.total', 3);

        $res = $this->getJson('/api/journals?page=1&per_page=25&status=posted', $headers);
        $res->assertJsonPath('data.total', 2);

        $res = $this->getJson('/api/journals?page=1&per_page=25&status=draft', $headers);
        $res->assertJsonPath('data.total', 1);
    }

    public function test_status_comma_separated_now_works(): void
    {
        ['headers' => $headers] = $this->seedJournals();

        // Sebelum fase ini, where('status', 'draft,posted') literal selalu
        // 0 hasil. Sekarang harus mengembalikan gabungan draft+posted (3).
        $res = $this->getJson('/api/journals?page=1&per_page=25&status=draft,posted', $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 3);
    }

    public function test_include_void_and_status_void_still_works_as_before(): void
    {
        ['headers' => $headers] = $this->seedJournals();

        $res = $this->getJson('/api/journals?page=1&per_page=25&include_void=true&status=void', $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath('data.data.0.journal_number', 'JV-2026-000004');
    }

    /**
     * Skenario nyata yang dilaporkan user: void satu jurnal lewat UI, lalu
     * centang filter status "Void" -- UI tidak pernah mengirim include_void,
     * cuma status=void. Sebelum diperbaiki, where('status','!=','void') yang
     * tanpa syarat bentrok dengan whereIn('status',['void']) dari
     * AppliesListQuery: kontradiksi, selalu 0 hasil walau jurnalnya memang ada.
     */
    public function test_status_void_filter_works_without_include_void_flag(): void
    {
        ['headers' => $headers] = $this->seedJournals();

        $res = $this->getJson('/api/journals?page=1&per_page=25&status=void', $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath('data.data.0.journal_number', 'JV-2026-000004');
    }

    /** status=draft,void (tanpa include_void) juga harus menghormati filter eksplisit. */
    public function test_status_filter_including_void_without_include_void_flag(): void
    {
        ['headers' => $headers] = $this->seedJournals();

        $res = $this->getJson('/api/journals?page=1&per_page=25&status=draft,void', $headers);
        $res->assertStatus(200);
        $numbers = collect($res->json('data.data'))->pluck('journal_number')->sort()->values()->all();
        $this->assertSame(['JV-2026-000001', 'JV-2026-000004'], $numbers);
    }

    public function test_date_range_is_inclusive(): void
    {
        ['headers' => $headers] = $this->seedJournals();

        $res = $this->getJson('/api/journals?page=1&per_page=25&date_from=2026-01-10&date_to=2026-02-15', $headers);
        $res->assertStatus(200);
        $numbers = collect($res->json('data.data'))->pluck('journal_number')->sort()->values()->all();
        $this->assertSame(['JV-2026-000001', 'JV-2026-000002'], $numbers);
    }

    public function test_sort_by_allowed_column(): void
    {
        ['headers' => $headers] = $this->seedJournals();

        $res = $this->getJson('/api/journals?page=1&per_page=25&status=posted&sort_by=journal_number&sort_direction=asc', $headers);
        $res->assertStatus(200);
        $numbers = collect($res->json('data.data'))->pluck('journal_number')->all();
        $this->assertSame(['JV-2026-000002', 'JV-2026-000003'], $numbers);
    }

    public function test_sort_by_outside_allowlist_falls_back_to_default(): void
    {
        ['headers' => $headers] = $this->seedJournals();

        // "description" bukan kolom di $listSortable -- harus jatuh ke default
        // (journal_date desc), bukan dipakai mentah di orderBy().
        $res = $this->getJson('/api/journals?page=1&per_page=25&status=posted&sort_by=description', $headers);
        $res->assertStatus(200);
        $numbers = collect($res->json('data.data'))->pluck('journal_number')->all();
        $this->assertSame(['JV-2026-000003', 'JV-2026-000002'], $numbers);
    }

    public function test_pagination_total_and_empty_page(): void
    {
        ['headers' => $headers] = $this->seedJournals();

        $res = $this->getJson('/api/journals?page=1&per_page=2', $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 3);
        $res->assertJsonPath('data.last_page', 2);
        $this->assertCount(2, $res->json('data.data'));

        $empty = $this->getJson('/api/journals?page=999&per_page=25', $headers);
        $empty->assertStatus(200);
        $empty->assertJsonPath('data.total', 3);
        $empty->assertJsonPath('data.from', null);
        $empty->assertJsonPath('data.to', null);
    }

    /**
     * Seed jurnal berbaris supaya total debit/kredit per jurnal berbeda dan
     * urutannya bisa diuji.
     *
     * @return array{headers: array<string,string>}
     */
    private function seedJournalsWithLines(): array
    {
        $ctx = $this->setUpTenant();
        $accounts = $ctx['accounts'];

        $amounts = [
            'JV-2026-000101' => 500000,
            'JV-2026-000102' => 2500000,
            'JV-2026-000103' => 1000000,
        ];

        foreach ($amounts as $number => $amount) {
            $journal = JournalEntry::query()->create([
                'journal_number' => $number,
                'journal_date' => '2026-04-01',
                'description' => 'Jurnal '.$number,
                'status' => 'posted',
            ]);

            $journal->lines()->createMany([
                ['account_id' => $accounts['debit'], 'debit' => $amount, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $accounts['credit'], 'debit' => 0, 'credit' => $amount, 'line_order' => 2],
            ]);
        }

        return ['headers' => $ctx['headers']];
    }

    /** Daftar wajib mengirim agregat total_debit/total_credit, bukan hanya kolom tabel. */
    public function test_list_returns_debit_and_credit_totals(): void
    {
        ['headers' => $headers] = $this->seedJournalsWithLines();

        $res = $this->getJson('/api/journals?page=1&per_page=25&sort_by=journal_number&sort_direction=asc', $headers);
        $res->assertStatus(200);

        $rows = collect($res->json('data.data'));
        $first = $rows->firstWhere('journal_number', 'JV-2026-000101');

        $this->assertNotNull($first);
        $this->assertEquals(500000, (float) $first['total_debit']);
        $this->assertEquals(500000, (float) $first['total_credit']);
    }

    public function test_sort_by_total_debit(): void
    {
        ['headers' => $headers] = $this->seedJournalsWithLines();

        $asc = $this->getJson('/api/journals?page=1&per_page=25&sort_by=total_debit&sort_direction=asc', $headers);
        $asc->assertStatus(200);
        $this->assertSame(
            ['JV-2026-000101', 'JV-2026-000103', 'JV-2026-000102'],
            collect($asc->json('data.data'))->pluck('journal_number')->all(),
        );

        $desc = $this->getJson('/api/journals?page=1&per_page=25&sort_by=total_debit&sort_direction=desc', $headers);
        $desc->assertStatus(200);
        $this->assertSame(
            ['JV-2026-000102', 'JV-2026-000103', 'JV-2026-000101'],
            collect($desc->json('data.data'))->pluck('journal_number')->all(),
        );
    }

    public function test_sort_by_total_credit(): void
    {
        ['headers' => $headers] = $this->seedJournalsWithLines();

        $res = $this->getJson('/api/journals?page=1&per_page=25&sort_by=total_credit&sort_direction=desc', $headers);
        $res->assertStatus(200);
        $this->assertSame(
            ['JV-2026-000102', 'JV-2026-000103', 'JV-2026-000101'],
            collect($res->json('data.data'))->pluck('journal_number')->all(),
        );
    }

    /**
     * `users` ada di database pusat dan `journal_entries` di tenant, jadi nama
     * pembuat tidak bisa datang dari relasi Eloquent — pastikan resolusinya
     * benar-benar terlampir di response daftar maupun detail.
     */
    public function test_list_and_detail_include_creator_name(): void
    {
        $ctx = $this->setUpTenant();
        $headers = $ctx['headers'];

        $create = $this->postJson('/api/journals', [
            'journal_date' => '2026-05-10',
            'description' => 'Jurnal dengan pembuat',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 1000, 'credit' => 0],
                ['account_id' => $ctx['accounts']['credit'], 'debit' => 0, 'credit' => 1000],
            ],
        ], $headers)->assertStatus(201);

        $expectedName = $ctx['user']->name;

        $list = $this->getJson('/api/journals?page=1&per_page=25', $headers);
        $list->assertStatus(200);
        $list->assertJsonPath('data.data.0.created_by_name', $expectedName);

        $detail = $this->getJson('/api/journals/'.$create->json('data.id'), $headers);
        $detail->assertStatus(200);
        $detail->assertJsonPath('data.created_by_name', $expectedName);
    }

    /** Jurnal tanpa `created_by` (mis. dibuat proses sistem) tetap valid, namanya null. */
    public function test_creator_name_is_null_when_created_by_is_empty(): void
    {
        $ctx = $this->setUpTenant();

        JournalEntry::query()->create([
            'journal_number' => 'JV-2026-000301',
            'journal_date' => '2026-05-11',
            'description' => 'Tanpa pembuat',
            'status' => 'posted',
        ]);

        $res = $this->getJson('/api/journals?page=1&per_page=25', $ctx['headers']);
        $res->assertStatus(200);
        $res->assertJsonPath('data.data.0.created_by_name', null);
    }

    public function test_is_system_generated_filter_hides_system_journals(): void
    {
        $ctx = $this->setUpTenant();
        $headers = $ctx['headers'];

        JournalEntry::query()->create([
            'journal_number' => 'JV-2026-000201',
            'journal_date' => '2026-05-01',
            'description' => 'Jurnal manual',
            'status' => 'posted',
            'is_system_generated' => false,
        ]);
        JournalEntry::query()->create([
            'journal_number' => 'SI-2026-000001',
            'journal_date' => '2026-05-02',
            'description' => 'Jurnal otomatis penjualan',
            'status' => 'posted',
            'is_system_generated' => true,
        ]);

        $manualOnly = $this->getJson('/api/journals?page=1&per_page=25&is_system_generated=false', $headers);
        $manualOnly->assertStatus(200);
        $manualOnly->assertJsonPath('data.total', 1);
        $manualOnly->assertJsonPath('data.data.0.journal_number', 'JV-2026-000201');

        $systemOnly = $this->getJson('/api/journals?page=1&per_page=25&is_system_generated=true', $headers);
        $systemOnly->assertStatus(200);
        $systemOnly->assertJsonPath('data.total', 1);
        $systemOnly->assertJsonPath('data.data.0.journal_number', 'SI-2026-000001');

        // Tanpa parameter: keduanya tetap tampil (perilaku lama tidak berubah).
        $all = $this->getJson('/api/journals?page=1&per_page=25', $headers);
        $all->assertJsonPath('data.total', 2);
    }
}
