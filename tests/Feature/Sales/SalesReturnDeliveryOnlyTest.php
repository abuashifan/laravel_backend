<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\DeliveryOrder;
use App\Models\Tenant\DeliveryOrderLine;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Support\AccountMapping\AccountMappingKey;

class SalesReturnDeliveryOnlyTest extends SalesTestCase
{
    private int $arAccountId;
    private Unit $unit;
    private Warehouse $warehouse;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_return_from_delivery_order_without_invoice_creates_sales_return_in_stock_movement(): void
    {
        $ctx = $this->setUpSalesReturnScenario();
        $delivery = $this->deliveredOrder($ctx);

        $return = $this->postJson('/api/sales/returns/from-delivery-order/'.$delivery['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->json('data');

        $this->patchJson('/api/sales/returns/'.$return['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        $movement = StockMovement::query()
            ->where('source_type', 'sales_return')
            ->where('source_id', $return['id'])
            ->firstOrFail();

        $this->assertSame('sales_return_in', (string) $movement->movement_type);
        $this->assertSame('posted', (string) $movement->status);
    }

    public function test_return_quantity_cannot_exceed_delivered_minus_returned_quantity(): void
    {
        $ctx = $this->setUpSalesReturnScenario();
        $delivery = $this->deliveredOrder($ctx, quantity: 2);

        $this->postJson('/api/sales/returns/from-delivery-order/'.$delivery['id'], [
            'lines' => [[
                'delivery_order_line_id' => $delivery['lines'][0]['id'],
                'product_id' => $this->product->id,
                'product_code' => $this->product->product_code,
                'description' => 'Returned item',
                'quantity' => 3,
                'unit_id' => $this->unit->id,
                'unit_price' => 0,
                'line_total' => 0,
                'warehouse_id' => $this->warehouse->id,
                'source_line_type' => 'delivery_order_line',
                'source_line_id' => $delivery['lines'][0]['id'],
            ]],
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'RETURN_QUANTITY_EXCEEDS_DELIVERED');
    }

    public function test_return_from_delivery_order_updates_delivery_line_returned_quantity(): void
    {
        $ctx = $this->setUpSalesReturnScenario();
        $delivery = $this->deliveredOrder($ctx, quantity: 4);
        $return = $this->createDeliveryReturn($ctx, $delivery, quantity: 2);

        $this->patchJson('/api/sales/returns/'.$return['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200);

        $line = DeliveryOrderLine::query()->findOrFail($delivery['lines'][0]['id']);
        $this->assertSame(2.0, (float) $line->returned_quantity);
    }

    public function test_void_delivery_order_return_restores_returned_quantity_and_voids_stock_movement(): void
    {
        $ctx = $this->setUpSalesReturnScenario();
        $delivery = $this->deliveredOrder($ctx, quantity: 4);
        $return = $this->createDeliveryReturn($ctx, $delivery, quantity: 2);

        $this->patchJson('/api/sales/returns/'.$return['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200);

        $movement = StockMovement::query()
            ->where('source_type', 'sales_return')
            ->where('source_id', $return['id'])
            ->firstOrFail();

        $this->patchJson('/api/sales/returns/'.$return['id'].'/void', ['reason' => 'Wrong return'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');

        $line = DeliveryOrderLine::query()->findOrFail($delivery['lines'][0]['id']);
        $this->assertSame(0.0, (float) $line->returned_quantity);
        $this->assertSame('void', (string) $movement->refresh()->status);
        $this->assertNotNull($movement->reversed_by_id);
    }

    public function test_return_from_void_delivery_order_is_rejected(): void
    {
        $ctx = $this->setUpSalesReturnScenario();
        $delivery = $this->deliveredOrder($ctx);

        $this->patchJson('/api/sales/delivery-orders/'.$delivery['id'].'/void', ['reason' => 'Cancelled shipment'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');

        $this->postJson('/api/sales/returns/from-delivery-order/'.$delivery['id'], [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SOURCE_NOT_CONVERTIBLE');
    }

    public function test_delivery_only_return_journal_has_no_accounts_receivable_line(): void
    {
        $ctx = $this->setUpSalesReturnScenario();
        $delivery = $this->deliveredOrder($ctx);
        $return = $this->createDeliveryReturn($ctx, $delivery);

        $this->patchJson('/api/sales/returns/'.$return['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200);

        $journal = JournalEntry::query()
            ->where('source_type', 'sales_return')
            ->where('source_id', $return['id'])
            ->firstOrFail();

        $this->assertSame(0, $journal->lines()->where('account_id', $this->arAccountId)->count());
        $this->assertSame(0, $journal->lines()->where('description', 'Accounts Receivable')->count());
    }

    private function setUpSalesReturnScenario(): array
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();

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
            'product_code' => 'SKU-RET',
            'product_name' => 'Returnable item',
            'product_type' => 'goods',
            'unit_id' => $this->unit->id,
            'is_stock_item' => true,
            'is_active' => true,
        ]);

        $this->postOpeningStock($ctx, 20);

        return $ctx;
    }

    private function deliveredOrder(array $ctx, int $quantity = 3): array
    {
        $delivery = $this->postJson('/api/sales/delivery-orders', [
            'customer_id' => $this->createCustomer(),
            'delivery_date' => '2026-05-20',
            'lines' => [[
                'product_id' => $this->product->id,
                'product_code' => $this->product->product_code,
                'description' => 'Delivered item',
                'quantity' => $quantity,
                'unit_id' => $this->unit->id,
                'warehouse_id' => $this->warehouse->id,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/delivery-orders/'.$delivery['id'].'/deliver', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'delivered');

        return DeliveryOrder::query()->with('lines')->findOrFail($delivery['id'])->toArray();
    }

    private function createDeliveryReturn(array $ctx, array $delivery, int $quantity = 1): array
    {
        return $this->postJson('/api/sales/returns/from-delivery-order/'.$delivery['id'], [
            'lines' => [[
                'delivery_order_line_id' => $delivery['lines'][0]['id'],
                'product_id' => $this->product->id,
                'product_code' => $this->product->product_code,
                'description' => 'Returned item',
                'quantity' => $quantity,
                'unit_id' => $this->unit->id,
                'unit_price' => 0,
                'line_total' => 0,
                'warehouse_id' => $this->warehouse->id,
                'source_line_type' => 'delivery_order_line',
                'source_line_id' => $delivery['lines'][0]['id'],
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');
    }

    private function postOpeningStock(array $ctx, int $quantity): void
    {
        $movement = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-05-01',
            'movement_type' => 'opening_stock',
            'lines' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'unit_id' => $this->unit->id,
                'quantity' => $quantity,
                'unit_cost' => 1000,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/inventory/stock-movements/'.$movement['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200);
    }

    private function seedMappings(): void
    {
        $this->arAccountId = $this->account('1100', 'Accounts Receivable', 'asset', 'debit');
        $revenue = $this->account('4100', 'Revenue', 'revenue', 'credit');
        $salesReturn = $this->account('4200', 'Sales Return', 'revenue', 'debit');
        $inventory = $this->account('1400', 'Inventory', 'asset', 'debit');
        $cogs = $this->account('5100', 'COGS', 'expense', 'debit');
        $equity = $this->account('3000', 'Opening Balance Equity', 'equity', 'credit');

        foreach ([
            'sales.accounts_receivable' => [$this->arAccountId, 'sales'],
            'sales.revenue' => [$revenue, 'sales'],
            'sales.return' => [$salesReturn, 'sales'],
            AccountMappingKey::INVENTORY_ASSET => [$inventory, 'inventory'],
            AccountMappingKey::INVENTORY_COGS => [$cogs, 'inventory'],
            AccountMappingKey::OPENING_BALANCE_EQUITY => [$equity, 'opening_balance'],
        ] as $key => [$accountId, $module]) {
            AccountMapping::query()->create([
                'mapping_key' => $key,
                'module' => $module,
                'account_id' => $accountId,
                'is_required' => true,
                'is_active' => true,
            ]);
        }
    }

    private function account(string $code, string $name, string $type, string $normal): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ])->id;
    }
}
