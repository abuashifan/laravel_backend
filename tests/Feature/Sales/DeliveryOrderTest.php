<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeliveryOrderTest extends SalesTestCase
{
    public function test_create_delivery_order_directly(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/sales/delivery-orders', $this->deliveryPayload(), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.lines.0.quantity', 2);

        $this->assertDatabaseCount('delivery_orders', 1, 'tenant');
        $this->assertDatabaseCount('delivery_order_lines', 1, 'tenant');
    }

    public function test_create_delivery_order_from_sales_order(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx);

        $this->postJson('/api/sales/delivery-orders/from-sales-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.sales_order_id', $order['id'])
            ->assertJsonPath('data.lines.0.sales_order_line_id', $order['lines'][0]['id']);
    }

    public function test_partial_delivery_works(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx, ['lines' => [['description' => 'Goods', 'quantity' => 10, 'unit_price' => 100]]]);

        $delivery = $this->postJson('/api/sales/delivery-orders/from-sales-order/'.$order['id'], [
            'lines' => [[
                'sales_order_line_id' => $order['lines'][0]['id'],
                'description' => 'Goods',
                'quantity' => 4,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/delivery-orders/'.$delivery['id'].'/deliver', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'delivered');

        $this->assertSame(4.0, (float) SalesOrderLine::query()->find($order['lines'][0]['id'])->delivered_quantity);
        $this->assertSame('partially_delivered', SalesOrder::query()->find($order['id'])->status);
    }

    public function test_multiple_delivery_orders_from_one_sales_order_work(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx, ['lines' => [['description' => 'Goods', 'quantity' => 10, 'unit_price' => 100]]]);
        $lineId = $order['lines'][0]['id'];

        foreach ([4, 6] as $quantity) {
            $delivery = $this->postJson('/api/sales/delivery-orders/from-sales-order/'.$order['id'], [
                'lines' => [['sales_order_line_id' => $lineId, 'description' => 'Goods', 'quantity' => $quantity]],
            ], $ctx['headers'])->assertStatus(201)->json('data');

            $this->patchJson('/api/sales/delivery-orders/'.$delivery['id'].'/deliver', [], $ctx['headers'])->assertStatus(200);
        }

        $this->assertSame(10.0, (float) SalesOrderLine::query()->find($lineId)->delivered_quantity);
        $this->assertSame('delivered', SalesOrder::query()->find($order['id'])->status);
    }

    public function test_cannot_deliver_more_than_remaining_sales_order_quantity(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx, ['lines' => [['description' => 'Goods', 'quantity' => 5, 'unit_price' => 100]]]);

        $this->postJson('/api/sales/delivery-orders/from-sales-order/'.$order['id'], [
            'lines' => [['sales_order_line_id' => $order['lines'][0]['id'], 'description' => 'Goods', 'quantity' => 6]],
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_delivered_quantity_updates_sales_order_line_and_status(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx, ['lines' => [['description' => 'Goods', 'quantity' => 3, 'unit_price' => 100]]]);

        $delivery = $this->postJson('/api/sales/delivery-orders/from-sales-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/delivery-orders/'.$delivery['id'].'/deliver', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(3.0, (float) SalesOrderLine::query()->find($order['lines'][0]['id'])->delivered_quantity);
        $this->assertSame('delivered', SalesOrder::query()->find($order['id'])->status);
    }

    public function test_delivery_order_does_not_create_stock_movement_or_journal(): void
    {
        $ctx = $this->setUpTenant();
        $delivery = $this->postJson('/api/sales/delivery-orders', $this->deliveryPayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/delivery-orders/'.$delivery['id'].'/deliver', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_cancel_and_void_delivery_order(): void
    {
        $ctx = $this->setUpTenant();
        $cancelled = $this->postJson('/api/sales/delivery-orders', $this->deliveryPayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/delivery-orders/'.$cancelled['id'].'/cancel', ['reason' => 'Hold'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $voided = $this->postJson('/api/sales/delivery-orders', $this->deliveryPayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/delivery-orders/'.$voided['id'].'/void', ['reason' => 'Wrong'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');
    }

    public function test_permission_denied_for_viewer(): void
    {
        $ctx = $this->setUpTenant('viewer');

        $this->postJson('/api/sales/delivery-orders', $this->deliveryPayload(), $ctx['headers'])
            ->assertStatus(403);
    }

    public function test_tenant_isolation(): void
    {
        $ctxA = $this->setUpTenant();
        $this->postJson('/api/sales/delivery-orders', $this->deliveryPayload(), $ctxA['headers'])->assertStatus(201);

        $ctxB = $this->setUpTenant();
        $this->getJson('/api/sales/delivery-orders', $ctxB['headers'])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    private function createSalesOrder(array $ctx, array $overrides = []): array
    {
        return $this->postJson('/api/sales/orders', array_replace_recursive([
            'customer_id' => $this->createCustomer(),
            'order_date' => '2026-05-20',
            'lines' => [['description' => 'Goods', 'quantity' => 2, 'unit_price' => 100]],
        ], $overrides), $ctx['headers'])->assertStatus(201)->json('data');
    }

    private function deliveryPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $this->createCustomer(),
            'delivery_date' => '2026-05-20',
            'lines' => [
                [
                    'description' => 'Goods',
                    'quantity' => 2,
                ],
            ],
        ], $overrides);
    }
}
