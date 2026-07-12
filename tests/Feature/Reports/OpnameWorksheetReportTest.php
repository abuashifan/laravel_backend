<?php

namespace Tests\Feature\Reports;

use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameLine;
use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Models\Warehouse;
use Tests\Feature\Journal\JournalTestCase;

class OpnameWorksheetReportTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/inventory/reports/opname-worksheet')->assertStatus(401);
    }

    public function test_permission_denied_for_viewer(): void
    {
        $ctx = $this->setUpTenant(role: 'viewer');
        $this->getJson('/api/inventory/reports/opname-worksheet', $ctx['headers'])->assertStatus(403);
    }

    public function test_invalid_status_returns_422(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->getJson('/api/inventory/reports/opname-worksheet?status=bogus', $ctx['headers'])->assertStatus(422);
    }

    public function test_empty_when_no_opname(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $res = $this->getJson('/api/inventory/reports/opname-worksheet', $ctx['headers'])->assertStatus(200);
        $this->assertNull($res->json('data.opname'));
        $this->assertSame([], $res->json('data.rows'));
    }

    public function test_worksheet_returns_system_vs_physical_difference(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p1 = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item 1', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);
        $p2 = Product::query()->create(['product_code' => 'SKU2', 'product_name' => 'Item 2', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $opname = StockOpname::query()->create([
            'opname_number' => 'OPN-001',
            'opname_date' => '2026-06-10',
            'warehouse_id' => $wh->id,
            'status' => 'counted',
        ]);

        // system 10, physical 8 → diff -2, value diff -2000
        StockOpnameLine::query()->create([
            'stock_opname_id' => $opname->id, 'product_id' => $p1->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id,
            'system_quantity' => 10, 'physical_quantity' => 8, 'difference_quantity' => -2, 'average_cost' => 1000, 'difference_value' => -2000, 'sort_order' => 1,
        ]);
        // system 5, physical 7 → diff +2, value diff +1000
        StockOpnameLine::query()->create([
            'stock_opname_id' => $opname->id, 'product_id' => $p2->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id,
            'system_quantity' => 5, 'physical_quantity' => 7, 'difference_quantity' => 2, 'average_cost' => 500, 'difference_value' => 1000, 'sort_order' => 2,
        ]);

        $res = $this->getJson('/api/inventory/reports/opname-worksheet?opname_id='.$opname->id, $ctx['headers'])->assertStatus(200);

        $res->assertJsonPath('data.opname.opname_number', 'OPN-001');
        $res->assertJsonPath('data.opname.warehouse_name', 'Main');

        $rows = collect($res->json('data.rows'))->keyBy('product_code');
        $this->assertEquals(-2.0, (float) $rows['SKU1']['difference_quantity']);
        $this->assertEquals(-2000.0, (float) $rows['SKU1']['difference_value']);
        $this->assertEquals(2.0, (float) $rows['SKU2']['difference_quantity']);

        $totals = $res->json('data.totals');
        $this->assertEquals(2, $totals['line_count']);
        $this->assertEquals(2, $totals['counted_lines']);
        $this->assertEquals(15.0, (float) $totals['total_system_quantity']);
        $this->assertEquals(15.0, (float) $totals['total_physical_quantity']);
        $this->assertEquals(-1000.0, (float) $totals['total_difference_value']);
    }

    public function test_latest_opname_selected_when_no_id_given(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);

        StockOpname::query()->create(['opname_number' => 'OPN-OLD', 'opname_date' => '2026-05-01', 'warehouse_id' => $wh->id, 'status' => 'finalized']);
        StockOpname::query()->create(['opname_number' => 'OPN-NEW', 'opname_date' => '2026-06-20', 'warehouse_id' => $wh->id, 'status' => 'counted']);

        $res = $this->getJson('/api/inventory/reports/opname-worksheet', $ctx['headers'])->assertStatus(200);
        $res->assertJsonPath('data.opname.opname_number', 'OPN-NEW');
    }
}
