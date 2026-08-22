<?php

namespace Tests\Feature\Shared;

use App\Modules\Journal\Models\JournalEntry;
use App\Shared\Api\AppliesListQuery;
use Illuminate\Database\Eloquent\Builder;
use Tests\Feature\MasterData\MasterDataTestCase;

/**
 * Kelas kecil yang cuma memakai trait dan membuka `applyListQuery()` sebagai
 * publik. Dipakai JournalEntry sebagai subjek uji karena skemanya pas untuk
 * menguji semua kemampuan trait (search 2 kolom, status, tanggal, sort) —
 * bukan berarti Fase 1 (pemindahan JournalEntryService) sudah dikerjakan.
 */
class AppliesListQueryHarness
{
    use AppliesListQuery;

    protected array $listSearchable = ['journal_number', 'description'];

    protected array $listSearchableRelations = [];

    protected string $listDateColumn = 'journal_date';

    protected string $listStatusColumn = 'status';

    protected array $listDefaultSort = ['journal_date' => 'desc', 'id' => 'desc'];

    protected array $listSortable = ['journal_number', 'journal_date'];

    /** @param array<string,mixed> $filters */
    public function run(Builder $query, array $filters)
    {
        return $this->applyListQuery($query, $filters);
    }
}

/**
 * Menguji `App\Shared\Api\AppliesListQuery` secara terisolasi dari service
 * manapun. Trait ini dipakai identik oleh 32 endpoint (lihat
 * `Finlite_knowladge/plans/list-query-pushdown`), jadi perilakunya cukup
 * dikunci di satu tempat.
 */
class AppliesListQueryTest extends MasterDataTestCase
{
    private function seedJournals(): void
    {
        JournalEntry::query()->create([
            'journal_number' => 'JV-0001',
            'journal_date' => '2026-01-10',
            'description' => 'Pembelian ATK',
            'status' => 'draft',
        ]);
        JournalEntry::query()->create([
            'journal_number' => 'JV-0002',
            'journal_date' => '2026-02-15',
            'description' => 'Setoran modal',
            'status' => 'posted',
        ]);
        JournalEntry::query()->create([
            'journal_number' => 'JV-0003',
            'journal_date' => '2026-03-20',
            'description' => 'Pembayaran listrik',
            'status' => 'posted',
        ]);
    }

    public function test_search_matches_declared_columns_only(): void
    {
        $this->setUpTenant();
        $this->seedJournals();
        $harness = new AppliesListQueryHarness;

        // Cocok journal_number.
        $byNumber = $harness->run(JournalEntry::query(), ['search' => '0002', 'per_page' => 10]);
        $this->assertSame(1, $byNumber->total());
        $this->assertSame('JV-0002', $byNumber->items()[0]->journal_number);

        // Cocok description.
        $byDescription = $harness->run(JournalEntry::query(), ['search' => 'listrik', 'per_page' => 10]);
        $this->assertSame(1, $byDescription->total());
        $this->assertSame('JV-0003', $byDescription->items()[0]->journal_number);

        // Tidak cocok kolom yang tidak didaftarkan (status ada di tiap baris,
        // tapi bukan bagian dari $listSearchable).
        $byStatus = $harness->run(JournalEntry::query(), ['search' => 'draft', 'per_page' => 10]);
        $this->assertSame(0, $byStatus->total());
    }

    public function test_search_or_does_not_leak_into_status_filter(): void
    {
        $this->setUpTenant();
        $this->seedJournals();
        $harness = new AppliesListQueryHarness;

        // "Pembayaran" cocok JV-0003 (posted), tapi status=draft harus tetap
        // mengecualikannya — OR pencarian tidak boleh bocor ke luar where().
        $result = $harness->run(JournalEntry::query(), [
            'search' => 'Pembayaran', 'status' => 'draft', 'per_page' => 10,
        ]);
        $this->assertSame(0, $result->total());
    }

    public function test_status_supports_comma_separated_values(): void
    {
        $this->setUpTenant();
        $this->seedJournals();
        $harness = new AppliesListQueryHarness;

        $result = $harness->run(JournalEntry::query(), ['status' => 'draft,posted', 'per_page' => 10]);
        $this->assertSame(3, $result->total());
    }

    public function test_date_range_is_inclusive_on_both_ends(): void
    {
        $this->setUpTenant();
        $this->seedJournals();
        $harness = new AppliesListQueryHarness;

        $result = $harness->run(JournalEntry::query(), [
            'date_from' => '2026-01-10', 'date_to' => '2026-02-15', 'per_page' => 10,
        ]);
        $numbers = collect($result->items())->pluck('journal_number')->sort()->values()->all();
        $this->assertSame(['JV-0001', 'JV-0002'], $numbers);
    }

    public function test_sort_by_outside_allowlist_falls_back_to_default(): void
    {
        $this->setUpTenant();
        $this->seedJournals();
        $harness = new AppliesListQueryHarness;

        // "status" bukan kolom sortable yang diizinkan — harus jatuh ke
        // default (journal_date desc), bukan dipakai mentah di orderBy().
        $result = $harness->run(JournalEntry::query(), [
            'sort_by' => 'status', 'sort_direction' => 'asc', 'per_page' => 10,
        ]);
        $numbers = collect($result->items())->pluck('journal_number')->all();
        $this->assertSame(['JV-0003', 'JV-0002', 'JV-0001'], $numbers);
    }

    public function test_per_page_is_clamped_to_100(): void
    {
        $this->setUpTenant();
        $this->seedJournals();
        $harness = new AppliesListQueryHarness;

        $result = $harness->run(JournalEntry::query(), ['per_page' => 500]);
        $this->assertSame(100, $result->perPage());
    }

    public function test_empty_page_has_null_from_and_to(): void
    {
        $this->setUpTenant();
        $this->seedJournals();
        $harness = new AppliesListQueryHarness;

        $result = $harness->run(JournalEntry::query(), ['page' => 999, 'per_page' => 10]);
        $this->assertSame(3, $result->total());
        $this->assertNull($result->firstItem());
        $this->assertNull($result->lastItem());
    }
}
