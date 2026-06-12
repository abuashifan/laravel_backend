<?php

namespace Tests\Feature\Inventory;

use App\Exceptions\ApiException;
use App\Models\CompanyUser;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Models\TenantDatabase;
use App\Services\Inventory\OpeningStockService;
use App\Services\Tenant\TenantContext;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\Feature\Journal\JournalTestCase;

class OpeningStockServiceTest extends JournalTestCase
{
    private Unit $unit;
    private Warehouse $warehouse;
    private Product $product;
    private int $inventoryAccountId;
    private int $equityAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $ctx = $this->setUpTenant(role: 'warehouse');
        $companyUser = CompanyUser::query()
            ->where('company_id', $ctx['company']->id)
            ->where('user_id', $ctx['user']->id)
            ->firstOrFail();
        $tenantDb = TenantDatabase::query()
            ->where('company_id', $ctx['company']->id)
            ->firstOrFail();

        app(TenantContext::class)->set($ctx['company'], $companyUser, $tenantDb);
        [$this->inventoryAccountId, $this->equityAccountId] = $this->seedInventoryMappings();

        $this->unit = Unit::query()->create([
            'code' => 'PCS',
            'name' => 'Pieces',
            'precision' => 0,
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::query()->create([
            'code' => 'WH1',
            'name' => 'Main',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->product = Product::query()->create([
            'product_code' => 'OS-001',
            'product_name' => 'Opening Stock Item',
            'product_type' => 'goods',
            'unit_id' => $this->unit->id,
            'is_stock_item' => true,
            'is_active' => true,
        ]);
    }

    public function test_new_product_warehouse_opening_stock_succeeds_and_creates_movement(): void
    {
        $movement = $this->postOpeningStock();

        $this->assertSame('opening_stock', (string) $movement->movement_type);
        $this->assertSame('posted', (string) $movement->status);
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'opening_stock')->where('status', 'posted')->count());
        $this->assertSame(1, $movement->lines()->where('product_id', $this->product->id)->where('warehouse_id', $this->warehouse->id)->count());
    }

    public function test_second_opening_stock_for_same_product_warehouse_is_rejected(): void
    {
        $this->postOpeningStock();

        try {
            $this->postOpeningStock();
            $this->fail('Expected duplicate opening stock to be rejected.');
        } catch (ApiException $e) {
            $this->assertSame('OPENING_STOCK_ALREADY_EXISTS', $e->codeName);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_non_stock_product_is_rejected(): void
    {
        $service = app(OpeningStockService::class);
        $serviceProduct = Product::query()->create([
            'product_code' => 'SVC-001',
            'product_name' => 'Consulting',
            'product_type' => 'service',
            'unit_id' => $this->unit->id,
            'is_stock_item' => false,
            'is_active' => true,
        ]);

        try {
            $service->post($this->payload(['product_id' => $serviceProduct->id]));
            $this->fail('Expected non-stock product to be rejected.');
        } catch (ApiException $e) {
            $this->assertSame('PRODUCT_NOT_STOCKABLE', $e->codeName);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_zero_quantity_is_rejected(): void
    {
        try {
            app(OpeningStockService::class)->post($this->payload(['quantity' => 0]));
            $this->fail('Expected zero quantity to be rejected.');
        } catch (ApiException $e) {
            $this->assertSame('QUANTITY_MUST_BE_POSITIVE', $e->codeName);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_journal_uses_canonical_opening_balance_equity_mapping(): void
    {
        $movement = $this->postOpeningStock(['quantity' => 4, 'unit_cost' => 1250]);

        $journal = JournalEntry::query()
            ->where('source_type', 'stock_movement')
            ->where('source_id', $movement->id)
            ->firstOrFail();

        $inventoryLine = $journal->lines()->where('account_id', $this->inventoryAccountId)->first();
        $equityLine = $journal->lines()->where('account_id', $this->equityAccountId)->first();

        $this->assertNotNull($inventoryLine);
        $this->assertNotNull($equityLine);
        $this->assertEqualsWithDelta(5000.0, (float) $inventoryLine->debit, 0.01);
        $this->assertEqualsWithDelta(5000.0, (float) $equityLine->credit, 0.01);
    }

    public function test_stock_balance_is_updated(): void
    {
        $this->postOpeningStock(['quantity' => 7, 'unit_cost' => 2000]);

        $balance = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();

        $this->assertSame(7.0, (float) $balance->quantity_on_hand);
        $this->assertSame(2000.0, (float) $balance->average_cost);
        $this->assertSame(14000.0, (float) $balance->total_value);
    }

    private function postOpeningStock(array $overrides = []): StockMovement
    {
        return app(OpeningStockService::class)->post($this->payload($overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'unit_cost' => 1000,
            'date' => '2026-01-01',
        ], $overrides);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function seedInventoryMappings(): array
    {
        $inventory = ChartOfAccount::query()->create([
            'account_code' => '1400',
            'account_name' => 'Inventory',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);
        $cogs = ChartOfAccount::query()->create([
            'account_code' => '5100',
            'account_name' => 'COGS',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);
        $equity = ChartOfAccount::query()->create([
            'account_code' => '3000',
            'account_name' => 'Opening Stock Equity',
            'account_type' => 'equity',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);
        $gain = ChartOfAccount::query()->create([
            'account_code' => '4100',
            'account_name' => 'Adj Gain',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);
        $loss = ChartOfAccount::query()->create([
            'account_code' => '5200',
            'account_name' => 'Adj Loss',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ASSET, 'module' => 'inventory', 'account_id' => $inventory->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_COGS, 'module' => 'inventory', 'account_id' => $cogs->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::OPENING_BALANCE_EQUITY, 'module' => 'opening_balance', 'account_id' => $equity->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN, 'module' => 'inventory', 'account_id' => $gain->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS, 'module' => 'inventory', 'account_id' => $loss->id, 'is_active' => true]);

        return [(int) $inventory->id, (int) $equity->id];
    }
}
