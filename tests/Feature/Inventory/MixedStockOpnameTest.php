<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\StockOpnameLine;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Support\AccountMapping\AccountMappingKey;
use Illuminate\Database\Eloquent\Collection;
use Tests\TenantTestCase;

class MixedStockOpnameTest extends TenantTestCase
{
    protected Unit $unit;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedInventoryMappings();
        $this->unit = Unit::query()->create([
            'code' => 'PCS',
            'name' => 'Pieces',
            'precision' => 0,
            'is_active' => true,
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'code' => 'WH1',
            'name' => 'Main',
            'is_default' => true,
        ]);
    }

    public function test_can_finalize_opname_with_all_positive_differences(): void
    {
        $productA = $this->createStockProduct('SKU-OP-IN-A');
        $productB = $this->createStockProduct('SKU-OP-IN-B');
        $this->seedBalance($productA, 20, 1000);
        $this->seedBalance($productB, 30, 1500);

        $opname = $this->createOpnameAndGenerateLines();
        $this->updatePhysicalCount($opname, $productA, 25);
        $this->updatePhysicalCount($opname, $productB, 35);

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/counted', [], $this->headers)
            ->assertSuccessful();
        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/finalize', [], $this->headers)
            ->assertSuccessful();

        $movements = $this->movementsForOpname((int) $opname->id);
        $this->assertSame(1, $movements->count());
        $this->assertSame('opname_in', (string) $movements->first()->movement_type);
        $this->assertSame(2, $movements->first()->lines()->count());
        $this->assertBalanceQuantity($productA, 25.0);
        $this->assertBalanceQuantity($productB, 35.0);
    }

    public function test_can_finalize_opname_with_all_negative_differences(): void
    {
        $productA = $this->createStockProduct('SKU-OP-OUT-A');
        $productB = $this->createStockProduct('SKU-OP-OUT-B');
        $this->seedBalance($productA, 50, 1000);
        $this->seedBalance($productB, 40, 1500);

        $opname = $this->createOpnameAndGenerateLines();
        $this->updatePhysicalCount($opname, $productA, 45);
        $this->updatePhysicalCount($opname, $productB, 35);

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/counted', [], $this->headers)
            ->assertSuccessful();
        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/finalize', [], $this->headers)
            ->assertSuccessful();

        $movements = $this->movementsForOpname((int) $opname->id);
        $this->assertSame(1, $movements->count());
        $this->assertSame('opname_out', (string) $movements->first()->movement_type);
        $this->assertSame(2, $movements->first()->lines()->count());
        $this->assertBalanceQuantity($productA, 45.0);
        $this->assertBalanceQuantity($productB, 35.0);
    }

    public function test_can_finalize_opname_with_zero_differences_no_movements_created(): void
    {
        $product = $this->createStockProduct('SKU-OP-ZERO');
        $this->seedBalance($product, 30, 1000);

        $opname = $this->createOpnameAndGenerateLines();
        $this->updatePhysicalCount($opname, $product, 30);

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/counted', [], $this->headers)
            ->assertSuccessful();
        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/finalize', [], $this->headers)
            ->assertSuccessful();

        $this->assertSame(0, $this->movementsForOpname((int) $opname->id)->count());
        $this->assertBalanceQuantity($product, 30.0);
    }

    public function test_can_finalize_opname_with_both_positive_and_negative_differences(): void
    {
        $productA = $this->createStockProduct('SKU-OP-MIX-A');
        $productB = $this->createStockProduct('SKU-OP-MIX-B');
        $this->seedBalance($productA, 50, 1000);
        $this->seedBalance($productB, 30, 1500);

        $opname = $this->createOpnameAndGenerateLines();
        $this->updatePhysicalCount($opname, $productA, 40);
        $this->updatePhysicalCount($opname, $productB, 35);

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/counted', [], $this->headers)
            ->assertSuccessful();
        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/finalize', [], $this->headers)
            ->assertSuccessful();

        $movements = $this->movementsForOpname((int) $opname->id);
        $this->assertSame(2, $movements->count());
        $this->assertSame(1, $movements->where('movement_type', 'opname_out')->count());
        $this->assertSame(1, $movements->where('movement_type', 'opname_in')->count());
        $this->assertBalanceQuantity($productA, 40.0);
        $this->assertBalanceQuantity($productB, 35.0);
    }

    public function test_blocks_finalize_when_opname_has_uncounted_lines_and_partial_count_is_disabled(): void
    {
        if ((bool) config('inventory.opname_allow_partial_count', false)) {
            $this->markTestSkipped('Partial stock opname counts are enabled.');
        }

        $productA = $this->createStockProduct('SKU-OP-PARTIAL-A');
        $productB = $this->createStockProduct('SKU-OP-PARTIAL-B');
        $this->seedBalance($productA, 20, 1000);
        $this->seedBalance($productB, 30, 1500);

        $opname = $this->createOpnameAndGenerateLines();
        $this->updatePhysicalCount($opname, $productA, 25);

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/counted', [], $this->headers)
            ->assertSuccessful();

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/finalize', [], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOCK_OPNAME_INCOMPLETE');
    }

    private function createOpnameAndGenerateLines(): StockOpname
    {
        $response = $this->postJson('/api/inventory/stock-opnames', [
            'opname_date' => '2026-01-10',
            'warehouse_id' => $this->warehouse->id,
        ], $this->headers)->assertCreated();

        $opname = StockOpname::query()->findOrFail((int) $response->json('data.id'));

        $this->postJson('/api/inventory/stock-opnames/'.$opname->id.'/generate-lines', [], $this->headers)
            ->assertSuccessful();

        return $opname->refresh()->load('lines');
    }

    private function updatePhysicalCount(StockOpname $opname, Product $product, float $physicalQuantity): StockOpnameLine
    {
        $line = StockOpnameLine::query()
            ->where('stock_opname_id', $opname->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/lines/'.$line->id, [
            'physical_quantity' => $physicalQuantity,
        ], $this->headers)->assertSuccessful();

        return $line->refresh();
    }

    private function createStockProduct(string $code): Product
    {
        return Product::factory()->stockItem()->create([
            'product_code' => $code,
            'product_name' => $code,
            'unit_id' => $this->unit->id,
        ]);
    }

    private function seedBalance(Product $product, float $quantity, float $averageCost): void
    {
        StockBalance::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity_on_hand' => $quantity,
            'quantity_reserved' => 0,
            'quantity_available' => $quantity,
            'average_cost' => $averageCost,
            'total_value' => $quantity * $averageCost,
        ]);
    }

    private function assertBalanceQuantity(Product $product, float $expected): void
    {
        $balance = StockBalance::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();

        $this->assertSame($expected, (float) $balance->quantity_on_hand);
    }

    /**
     * @return Collection<int, StockMovement>
     */
    private function movementsForOpname(int $opnameId): Collection
    {
        return StockMovement::query()
            ->where('source_type', 'stock_opname')
            ->where('source_id', $opnameId)
            ->orderBy('id')
            ->get();
    }

    private function seedInventoryMappings(): void
    {
        $inventory = ChartOfAccount::query()->create(['account_code' => '1400', 'account_name' => 'Inventory', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $cogs = ChartOfAccount::query()->create(['account_code' => '5100', 'account_name' => 'COGS', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $equity = ChartOfAccount::query()->create(['account_code' => '3000', 'account_name' => 'Equity', 'account_type' => 'equity', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $gain = ChartOfAccount::query()->create(['account_code' => '4100', 'account_name' => 'Adj Gain', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $loss = ChartOfAccount::query()->create(['account_code' => '5200', 'account_name' => 'Adj Loss', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);

        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ASSET, 'module' => 'inventory', 'account_id' => $inventory->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_COGS, 'module' => 'inventory', 'account_id' => $cogs->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::OPENING_BALANCE_EQUITY, 'module' => 'opening_balance', 'account_id' => $equity->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN, 'module' => 'inventory', 'account_id' => $gain->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS, 'module' => 'inventory', 'account_id' => $loss->id, 'is_active' => true]);
    }
}
