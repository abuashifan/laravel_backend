<?php

namespace Tests\Feature\Purchase;

use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\StockMovement;
use Illuminate\Support\Facades\DB;

class VendorBillTest extends PurchaseTestCase
{
    public function test_create_bill_directly_and_post_creates_ap_journal(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();

        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.grand_total', 222)
            ->json('data');

        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'vendor_bill')->count());
        $this->assertSame(3, DB::connection('tenant')->table('journal_entry_lines')->count());
        $this->assertSame(0, StockMovement::query()->count());

        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/void', ['reason' => 'Incorrect vendor bill'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');
        $this->assertSame('void', DB::connection('tenant')->table('journal_entries')->where('source_type', 'vendor_bill')->value('status'));
    }

    public function test_create_bill_from_purchase_order_copies_discount(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload([
            'is_taxable' => false,
            'header_discount_type' => 'percent',
            'header_discount_value' => 10,
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $this->postJson('/api/purchase/bills/from-purchase-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.purchase_order_id', $order['id'])
            ->assertJsonPath('data.header_discount_amount', 20);
    }

    public function test_create_bill_from_goods_receipt(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload(['is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $receipt = $this->postJson('/api/purchase/goods-receipts/from-purchase-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'].'/receive', [], $ctx['headers'])->assertStatus(200);

        $this->postJson('/api/purchase/bills/from-goods-receipt/'.$receipt['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.goods_receipt_id', $receipt['id'])
            ->assertJsonPath('data.lines.0.source_line_type', 'goods_receipt_line')
            ->assertJsonPath('data.lines.0.unit_price', 100);
    }

    public function test_bill_from_purchase_order_uses_remaining_quantity_and_updates_status_when_posted(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();
        $order = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload([
            'is_taxable' => false,
            'lines' => [['description' => 'Stock', 'quantity' => 5, 'unit_price' => 100]],
        ]), $ctx['headers'])->assertStatus(201)->json('data');
        $first = $this->postJson('/api/purchase/bills/from-purchase-order/'.$order['id'], [
            'lines' => [['purchase_order_line_id' => $order['lines'][0]['id'], 'quantity' => 2]],
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$first['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(2.0, (float) PurchaseOrderLine::query()->findOrFail($order['lines'][0]['id'])->billed_quantity);
        $this->assertSame('partially_billed', PurchaseOrder::query()->findOrFail($order['id'])->status);
        $this->postJson('/api/purchase/bills/from-purchase-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.lines.0.quantity', 3);
        $this->postJson('/api/purchase/bills/from-purchase-order/'.$order['id'], [
            'lines' => [['purchase_order_line_id' => $order['lines'][0]['id'], 'quantity' => 4]],
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_bill_from_goods_receipt_uses_received_remaining_and_tracks_receipt_progress(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();
        $order = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload([
            'is_taxable' => false,
            'lines' => [['description' => 'Stock', 'quantity' => 4, 'unit_price' => 80]],
        ]), $ctx['headers'])->assertStatus(201)->json('data');
        $receipt = $this->postJson('/api/purchase/goods-receipts/from-purchase-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'].'/receive', [], $ctx['headers'])->assertStatus(200);
        $first = $this->postJson('/api/purchase/bills/from-goods-receipt/'.$receipt['id'], [
            'lines' => [['goods_receipt_line_id' => $receipt['lines'][0]['id'], 'quantity' => 1]],
        ], $ctx['headers'])->assertStatus(201)->assertJsonPath('data.lines.0.unit_price', 80)->json('data');
        $this->patchJson('/api/purchase/bills/'.$first['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(1.0, (float) GoodsReceiptLine::query()->findOrFail($receipt['lines'][0]['id'])->billed_quantity);
        $this->postJson('/api/purchase/bills/from-goods-receipt/'.$receipt['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.lines.0.quantity', 3);
    }

    public function test_bill_applies_posted_vendor_deposit(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();
        $order = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload([
            'is_taxable' => false,
            'has_down_payment' => true,
            'vendor_deposit' => ['deposit_date' => '2026-05-20', 'cash_bank_account_id' => $accounts['cash'], 'amount' => 50],
        ]), $ctx['headers'])->assertStatus(201)->json('data');
        $depositId = DB::connection('tenant')->table('vendor_deposits')->where('purchase_order_id', $order['id'])->value('id');
        $this->patchJson('/api/purchase/vendor-deposits/'.$depositId.'/post', [], $ctx['headers'])->assertStatus(200);
        $bill = $this->postJson('/api/purchase/bills/from-purchase-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.paid_amount', 50);

        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/void', ['reason' => 'Remove bill allocation'], $ctx['headers'])->assertStatus(200);
        $this->assertSame('void', DB::connection('tenant')->table('vendor_deposit_allocations')->value('status'));
        $this->assertSame(50.0, (float) DB::connection('tenant')->table('vendor_deposits')->where('id', $depositId)->value('remaining_amount'));
    }

    public function test_permission_denied_for_viewer(): void
    {
        $ctx = $this->setUpTenant('viewer');
        $this->postJson('/api/purchase/bills', $this->vendorBillPayload(), $ctx['headers'])->assertStatus(403);
    }
}
