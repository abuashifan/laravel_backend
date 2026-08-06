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
}
