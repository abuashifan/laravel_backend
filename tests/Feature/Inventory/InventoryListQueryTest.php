<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\MasterData\Models\Warehouse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Menguji 3 endpoint daftar Persediaan setelah service-nya pindah ke SQL lewat
 * `AppliesListQuery` (Fase 5 / list-query-pushdown).
 *
 * Modul ini berangkat dari titik yang lebih maju daripada Sales/Purchase/Kas &
 * Bank: status comma-separated dan rentang tanggal SUDAH dikerjakan di SQL
 * sebelum fase ini, dan ketiga halaman frontend-nya sudah mengirim
 * `status`/`date_from`/`date_to` ke server (bukan menyaring di browser). Yang
 * benar-benar pindah di sini: pencarian, sort, dan paginasi.
 *
 * Kolom pencarian mengikuti janji placeholder tiap halaman, yang ternyata tidak
 * sama dengan daftar di 00-conventions.md 4 -- lihat phase-5-inventory.md.
 */
class InventoryListQueryTest extends JournalTestCase
{
    /**
     * @return array<string,array{0:string,1:class-string,2:string,3:string,4:string|null}>
     */
    public static function inventoryModules(): array
    {
        // [uri, model, kolom nomor, kolom tanggal, kolom teks kedua yang dicari]
        return [
            'stock-movements' => ['/api/inventory/stock-movements', StockMovement::class, 'movement_number', 'movement_date', 'description'],
            'stock-adjustments' => ['/api/inventory/stock-adjustments', StockAdjustment::class, 'adjustment_number', 'adjustment_date', 'reason'],
            'stock-opnames' => ['/api/inventory/stock-opnames', StockOpname::class, 'opname_number', 'opname_date', null],
        ];
    }

    /**
     * @param  class-string  $model
     * @return array{headers: array<string,string>, main: int, second: int}
     */
    private function seedDocuments(string $model, string $numberColumn, string $dateColumn, ?string $textColumn): array
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $main = (int) Warehouse::query()->create(['code' => 'WH1', 'name' => 'Gudang Utama', 'is_default' => true, 'is_active' => true])->id;
        $second = (int) Warehouse::query()->create(['code' => 'WH2', 'name' => 'Gudang Cabang', 'is_active' => true])->id;

        $rows = [
            ['DOC-000001', '2026-01-10', 'draft', $main],
            ['DOC-000002', '2026-02-15', 'posted', $main],
            ['DOC-000003', '2026-03-20', 'posted', $second],
        ];
        foreach ($rows as [$number, $date, $status, $warehouseId]) {
            $attributes = [
                $numberColumn => $number,
                $dateColumn => $date,
                'status' => $status,
                'warehouse_id' => $warehouseId,
            ];
            if ($textColumn !== null) {
                $attributes[$textColumn] = "Keterangan {$number}";
            }
            if ($model === StockMovement::class) {
                $attributes['movement_type'] = 'adjustment';
                $attributes['source_number'] = "SRC-{$number}";
            }

            $model::query()->create($attributes);
        }

        return ['headers' => $ctx['headers'], 'main' => $main, 'second' => $second];
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('inventoryModules')]
    public function test_search_matches_document_number(string $uri, string $model, string $numberColumn, string $dateColumn, ?string $textColumn): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $textColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&search=000002", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath("data.data.0.{$numberColumn}", 'DOC-000002');
    }

    /**
     * Mutasi mencari `description`, penyesuaian mencari `reason`, opname tidak
     * punya kolom teks kedua -- persis seperti janji placeholder-nya.
     *
     * @param  class-string  $model
     */
    #[DataProvider('inventoryModules')]
    public function test_search_matches_second_text_column_when_promised(string $uri, string $model, string $numberColumn, string $dateColumn, ?string $textColumn): void
    {
        if ($textColumn === null) {
            $this->markTestSkipped('Opname hanya menjanjikan pencarian nomor.');
        }

        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $textColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&search=Keterangan+DOC-000003", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath("data.data.0.{$numberColumn}", 'DOC-000003');
    }

    /**
     * Placeholder mutasi stok berbunyi "Nomor, sumber...", jadi `source_number`
     * ikut dicari -- di luar daftar 00-conventions.md 4 tapi sesuai prinsipnya.
     * Bandingkan dengan Jurnal (Fase 1), yang justru menguji `source_number`
     * TIDAK dicari karena placeholder-nya menjanjikan hal lain.
     */
    public function test_stock_movement_search_matches_source_number(): void
    {
        ['headers' => $headers] = $this->seedDocuments(StockMovement::class, 'movement_number', 'movement_date', 'description');

        $res = $this->getJson('/api/inventory/stock-movements?page=1&per_page=25&search=SRC-DOC-000002', $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath('data.data.0.movement_number', 'DOC-000002');
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('inventoryModules')]
    public function test_search_does_not_leak_past_status_filter(string $uri, string $model, string $numberColumn, string $dateColumn, ?string $textColumn): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $textColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&search=DOC-&status=draft", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath("data.data.0.{$numberColumn}", 'DOC-000001');
    }

    /**
     * Filter khusus modul yang tetap tinggal di `list()`, bukan pindah ke trait.
     *
     * @param  class-string  $model
     */
    #[DataProvider('inventoryModules')]
    public function test_warehouse_filter_still_applies(string $uri, string $model, string $numberColumn, string $dateColumn, ?string $textColumn): void
    {
        ['headers' => $headers, 'main' => $main] = $this->seedDocuments($model, $numberColumn, $dateColumn, $textColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&warehouse_id={$main}", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 2);
        $numbers = collect($res->json('data.data'))->pluck($numberColumn)->sort()->values()->all();
        $this->assertSame(['DOC-000001', 'DOC-000002'], $numbers);
    }

    /** `movement_type` juga tetap di service, tidak digabung ke $listStatusColumn. */
    public function test_stock_movement_type_filter_still_applies(): void
    {
        ['headers' => $headers] = $this->seedDocuments(StockMovement::class, 'movement_number', 'movement_date', 'description');

        $this->getJson('/api/inventory/stock-movements?page=1&per_page=25&movement_type=adjustment', $headers)
            ->assertJsonPath('data.total', 3);

        $this->getJson('/api/inventory/stock-movements?page=1&per_page=25&movement_type=opening_stock', $headers)
            ->assertJsonPath('data.total', 0);
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('inventoryModules')]
    public function test_status_filter_supports_single_and_comma_separated(string $uri, string $model, string $numberColumn, string $dateColumn, ?string $textColumn): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $textColumn);

        $this->getJson("{$uri}?page=1&per_page=25&status=posted", $headers)
            ->assertJsonPath('data.total', 2);

        $this->getJson("{$uri}?page=1&per_page=25&status=draft,posted", $headers)
            ->assertJsonPath('data.total', 3);
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('inventoryModules')]
    public function test_date_range_is_inclusive(string $uri, string $model, string $numberColumn, string $dateColumn, ?string $textColumn): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $textColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&date_from=2026-01-10&date_to=2026-02-15", $headers);
        $res->assertStatus(200);
        $numbers = collect($res->json('data.data'))->pluck($numberColumn)->sort()->values()->all();
        $this->assertSame(['DOC-000001', 'DOC-000002'], $numbers);
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('inventoryModules')]
    public function test_sort_default_and_allowlist(string $uri, string $model, string $numberColumn, string $dateColumn, ?string $textColumn): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $textColumn);

        $default = $this->getJson("{$uri}?page=1&per_page=25", $headers);
        $this->assertSame(
            ['DOC-000003', 'DOC-000002', 'DOC-000001'],
            collect($default->json('data.data'))->pluck($numberColumn)->all(),
        );

        $asc = $this->getJson("{$uri}?page=1&per_page=25&sort_by={$dateColumn}&sort_direction=asc", $headers);
        $this->assertSame(
            ['DOC-000001', 'DOC-000002', 'DOC-000003'],
            collect($asc->json('data.data'))->pluck($numberColumn)->all(),
        );

        $bogus = $this->getJson("{$uri}?page=1&per_page=25&sort_by=warehouse_id;drop", $headers);
        $bogus->assertStatus(200);
        $this->assertSame(
            ['DOC-000003', 'DOC-000002', 'DOC-000001'],
            collect($bogus->json('data.data'))->pluck($numberColumn)->all(),
        );
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('inventoryModules')]
    public function test_pagination_shape_is_unchanged(string $uri, string $model, string $numberColumn, string $dateColumn, ?string $textColumn): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $textColumn);

        $page1 = $this->getJson("{$uri}?page=1&per_page=2", $headers);
        $page1->assertStatus(200);
        $page1->assertJsonPath('data.total', 3);
        $page1->assertJsonPath('data.current_page', 1);
        $page1->assertJsonPath('data.per_page', 2);
        $page1->assertJsonPath('data.last_page', 2);
        $page1->assertJsonPath('data.from', 1);
        $page1->assertJsonPath('data.to', 2);
        $this->assertCount(2, $page1->json('data.data'));

        $empty = $this->getJson("{$uri}?page=999&per_page=25", $headers);
        $empty->assertJsonPath('data.total', 3);
        $empty->assertJsonPath('data.from', null);
        $empty->assertJsonPath('data.to', null);
        $this->assertCount(0, $empty->json('data.data'));
    }

    /**
     * `withCount('lines')` di StockAdjustment & StockOpname harus tetap ikut
     * setelah pindah ke `->paginate()`.
     *
     * @param  class-string  $model
     */
    #[DataProvider('inventoryModules')]
    public function test_lines_count_still_present_for_documents_that_had_it(string $uri, string $model, string $numberColumn, string $dateColumn, ?string $textColumn): void
    {
        if ($model === StockMovement::class) {
            $this->markTestSkipped('StockMovement::list() memang tidak memakai withCount.');
        }

        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $textColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.data.0.lines_count', 0);
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('inventoryModules')]
    public function test_without_page_params_returns_unpaginated_list(string $uri, string $model, string $numberColumn, string $dateColumn, ?string $textColumn): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $textColumn);

        $res = $this->getJson($uri, $headers);
        $res->assertStatus(200);
        $this->assertCount(3, $res->json('data'));
        $this->assertSame('DOC-000003', $res->json("data.0.{$numberColumn}"));
    }
}
