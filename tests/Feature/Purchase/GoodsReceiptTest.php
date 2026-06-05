<?php

namespace Tests\Feature\Purchase;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\StockMovement;
use Illuminate\Support\Facades\DB;

class GoodsReceiptTest extends PurchaseTestCase
{
    public function test_create_goods_receipt_directly(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/purchase/goods-receipts', $this->goodsReceiptPayload(), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseCount('goods_receipts', 1, 'tenant');
        $this->assertDatabaseCount('goods_receipt_lines', 1, 'tenant');
    }

    public function test_create_goods_receipt_from_purchase_order(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createOrder($ctx);

        $this->postJson('/api/purchase/goods-receipts/from-purchase-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.purchase_order_id', $order['id'])
            ->assertJsonPath('data.lines.0.purchase_order_line_id', $order['lines'][0]['id']);
    }

    public function test_partial_receipt_works_and_updates_purchase_order_status(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createOrder($ctx);

        $receipt = $this->postJson('/api/purchase/goods-receipts/from-purchase-order/'.$order['id'], [
            'lines' => [[
                'purchase_order_line_id' => $order['lines'][0]['id'],
                'description' => $order['lines'][0]['description'],
                'quantity' => 1,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'].'/receive', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'received');

        $this->assertSame(1.0, (float) PurchaseOrderLine::query()->find($order['lines'][0]['id'])->received_quantity);
        $this->assertSame('partially_received', PurchaseOrder::query()->find($order['id'])->status);
    }

    public function test_multiple_goods_receipts_from_one_purchase_order_work(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createOrder($ctx);

        foreach ([1, 1] as $qty) {
            $receipt = $this->postJson('/api/purchase/goods-receipts/from-purchase-order/'.$order['id'], [
                'lines' => [[
                    'purchase_order_line_id' => $order['lines'][0]['id'],
                    'description' => $order['lines'][0]['description'],
                    'quantity' => $qty,
                ]],
            ], $ctx['headers'])->assertStatus(201)->json('data');
            $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'].'/receive', [], $ctx['headers'])->assertStatus(200);
        }

        $this->assertSame(2.0, (float) PurchaseOrderLine::query()->find($order['lines'][0]['id'])->received_quantity);
        $this->assertSame('received', PurchaseOrder::query()->find($order['id'])->status);
    }

    public function test_cannot_receive_more_than_remaining_purchase_order_quantity(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createOrder($ctx);

        $receipt = $this->postJson('/api/purchase/goods-receipts/from-purchase-order/'.$order['id'], [
            'lines' => [[
                'purchase_order_line_id' => $order['lines'][0]['id'],
                'description' => $order['lines'][0]['description'],
                'quantity' => 3,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'].'/receive', [], $ctx['headers'])->assertStatus(422);
    }

    public function test_goods_receipt_does_not_create_stock_movement_or_journal(): void
    {
        $ctx = $this->setUpTenant();
        $receipt = $this->postJson('/api/purchase/goods-receipts', $this->goodsReceiptPayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'].'/receive', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_cancel_goods_receipt(): void
    {
        $ctx = $this->setUpTenant();
        $receipt = $this->postJson('/api/purchase/goods-receipts', $this->goodsReceiptPayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'].'/cancel', ['reason' => 'Duplicate'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_void_goods_receipt_reverses_purchase_order_received_quantity(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createOrder($ctx);
        $receipt = $this->postJson('/api/purchase/goods-receipts/from-purchase-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'].'/receive', [], $ctx['headers'])->assertStatus(200);

        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'].'/void', ['reason' => 'Wrong receipt'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');

        $this->assertSame(0.0, (float) PurchaseOrderLine::query()->find($order['lines'][0]['id'])->received_quantity);
    }

    public function test_permission_denied_for_viewer(): void
    {
        $ctx = $this->setUpTenant('viewer');
        $this->postJson('/api/purchase/goods-receipts', $this->goodsReceiptPayload(), $ctx['headers'])->assertStatus(403);
    }

    public function test_tenant_isolation(): void
    {
        $ctxA = $this->setUpTenant();
        $this->postJson('/api/purchase/goods-receipts', $this->goodsReceiptPayload(), $ctxA['headers'])->assertStatus(201);

        $ctxB = $this->setUpTenant();
        $this->assertSame(0, GoodsReceipt::query()->count());

        $this->getJson('/api/purchase/goods-receipts', $ctxB['headers'])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    private function createOrder(array $ctx): array
    {
        return $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload([
            'is_taxable' => false,
        ]), $ctx['headers'])->assertStatus(201)->json('data');
    }
}
