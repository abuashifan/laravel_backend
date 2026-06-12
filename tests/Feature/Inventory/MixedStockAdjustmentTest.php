<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\TenantTestCase;

class MixedStockAdjustmentTest extends TenantTestCase
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

    public function test_can_post_adjustment_with_increase_only_lines(): void
    {
        $product = $this->createStockProduct('SKU-IN');

        $adjustmentId = $this->createApprovedAdjustment([
            $this->linePayload($product, 'increase', 10, 1000),
        ]);

        $this->patchJson('/api/inventory/stock-adjustments/'.$adjustmentId.'/post', [], $this->headers)
            ->assertSuccessful();

        $this->assertSame(1, $this->movementsForAdjustment($adjustmentId)->count());
        $this->assertDatabaseHas('stock_movements', [
            'source_type' => 'stock_adjustment',
            'source_id' => $adjustmentId,
            'movement_type' => 'adjustment_in',
            'status' => 'posted',
        ], 'tenant');

        $this->assertBalanceQuantity($product, 10.0);
    }

    public function test_can_post_adjustment_with_decrease_only_lines(): void
    {
        $product = $this->createStockProduct('SKU-OUT');
        $this->seedBalance($product, 50, 1000);

        $adjustmentId = $this->createApprovedAdjustment([
            $this->linePayload($product, 'decrease', 10),
        ]);

        $this->patchJson('/api/inventory/stock-adjustments/'.$adjustmentId.'/post', [], $this->headers)
            ->assertSuccessful();

        $this->assertSame(1, $this->movementsForAdjustment($adjustmentId)->count());
        $this->assertDatabaseHas('stock_movements', [
            'source_type' => 'stock_adjustment',
            'source_id' => $adjustmentId,
            'movement_type' => 'adjustment_out',
            'status' => 'posted',
        ], 'tenant');

        $this->assertBalanceQuantity($product, 40.0);
    }

    public function test_can_post_adjustment_with_both_increase_and_decrease_lines(): void
    {
        $productA = $this->createStockProduct('SKU-MIX-A');
        $productB = $this->createStockProduct('SKU-MIX-B');
        $this->seedBalance($productA, 100, 1000);

        $adjustmentId = $this->createApprovedAdjustment([
            $this->linePayload($productA, 'decrease', 10),
            $this->linePayload($productB, 'increase', 5, 1200),
        ]);

        $this->patchJson('/api/inventory/stock-adjustments/'.$adjustmentId.'/post', [], $this->headers)
            ->assertSuccessful();

        $movements = $this->movementsForAdjustment($adjustmentId);
        $this->assertSame(2, $movements->count());
        $this->assertSame(1, $movements->where('movement_type', 'adjustment_out')->count());
        $this->assertSame(1, $movements->where('movement_type', 'adjustment_in')->count());
        $this->assertBalanceQuantity($productA, 90.0);
        $this->assertBalanceQuantity($productB, 5.0);
    }

    public function test_can_post_adjustment_with_multiple_increase_lines_for_different_products(): void
    {
        $productA = $this->createStockProduct('SKU-MULTI-A');
        $productB = $this->createStockProduct('SKU-MULTI-B');
        $productC = $this->createStockProduct('SKU-MULTI-C');

        $adjustmentId = $this->createApprovedAdjustment([
            $this->linePayload($productA, 'increase', 3, 1000),
            $this->linePayload($productB, 'increase', 7, 1500),
            $this->linePayload($productC, 'increase', 11, 2000),
        ]);

        $this->patchJson('/api/inventory/stock-adjustments/'.$adjustmentId.'/post', [], $this->headers)
            ->assertSuccessful();

        $movements = $this->movementsForAdjustment($adjustmentId);
        $this->assertSame(1, $movements->count());
        $this->assertSame('adjustment_in', (string) $movements->first()->movement_type);
        $this->assertSame(3, $movements->first()->lines()->count());
        $this->assertBalanceQuantity($productA, 3.0);
        $this->assertBalanceQuantity($productB, 7.0);
        $this->assertBalanceQuantity($productC, 11.0);
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function createApprovedAdjustment(array $lines): int
    {
        $response = $this->postJson('/api/inventory/stock-adjustments', [
            'adjustment_date' => '2026-01-10',
            'warehouse_id' => $this->warehouse->id,
            'reason' => 'Mixed stock adjustment regression test',
            'lines' => $lines,
        ], $this->headers)->assertCreated();

        $adjustmentId = (int) $response->json('data.id');

        $this->patchJson('/api/inventory/stock-adjustments/'.$adjustmentId.'/approve', [], $this->headers)
            ->assertSuccessful();

        return $adjustmentId;
    }

    private function createStockProduct(string $code): Product
    {
        return Product::factory()->stockItem()->create([
            'product_code' => $code,
            'product_name' => $code,
            'unit_id' => $this->unit->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function linePayload(Product $product, string $type, float $quantity, ?float $unitCost = null): array
    {
        $payload = [
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'unit_id' => $this->unit->id,
            'adjustment_type' => $type,
            'quantity' => $quantity,
        ];

        if ($unitCost !== null) {
            $payload['unit_cost'] = $unitCost;
        }

        return $payload;
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

    private function movementsForAdjustment(int $adjustmentId)
    {
        return StockMovement::query()
            ->where('source_type', 'stock_adjustment')
            ->where('source_id', $adjustmentId)
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
