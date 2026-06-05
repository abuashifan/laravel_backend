<?php

namespace Tests\Feature\Purchase;

use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\VendorBillLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseReturnTest extends PurchaseTestCase
{
    public function test_create_return_from_vendor_bill(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();
        $bill = $this->postedBill($ctx);

        $this->postJson('/api/purchase/returns/from-bill/'.$bill['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.vendor_bill_id', $bill['id'])
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_create_return_from_goods_receipt(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload(['is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $receipt = $this->postJson('/api/purchase/goods-receipts/from-purchase-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');

        $this->postJson('/api/purchase/returns/from-goods-receipt/'.$receipt['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.goods_receipt_id', $receipt['id']);
    }

    public function test_cannot_return_more_than_billed_quantity(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();
        $bill = $this->postedBill($ctx);
        $line = VendorBillLine::query()->where('vendor_bill_id', $bill['id'])->firstOrFail();

        $this->postJson('/api/purchase/returns', [
            'return_date' => '2026-05-20',
            'vendor_id' => $bill['vendor_id'],
            'vendor_bill_id' => $bill['id'],
            'lines' => [[
                'vendor_bill_line_id' => $line->id,
                'description' => $line->description,
                'quantity' => 3,
                'unit_price' => $line->unit_price,
            ]],
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_post_return_creates_ap_journal_and_updates_bill_balance(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();
        $bill = $this->postedBill($ctx);
        $return = $this->postJson('/api/purchase/returns/from-bill/'.$bill['id'], [
            'lines' => [[
                'vendor_bill_line_id' => $bill['lines'][0]['id'],
                'description' => $bill['lines'][0]['description'],
                'quantity' => 1,
                'unit_price' => 100,
                'tax_amount' => 11,
                'line_total' => 111,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/returns/'.$return['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        $freshBill = VendorBill::query()->findOrFail($bill['id']);
        $this->assertSame(111.0, (float) $freshBill->returned_amount);
        $this->assertSame(111.0, (float) $freshBill->balance_due);
        $this->assertSame(1.0, (float) VendorBillLine::query()->findOrFail($bill['lines'][0]['id'])->returned_quantity);
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'purchase_return')->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_post_goods_receipt_return_updates_returned_quantity_without_stock_movement(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();
        $order = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload(['is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $receipt = $this->postJson('/api/purchase/goods-receipts/from-purchase-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $return = $this->postJson('/api/purchase/returns/from-goods-receipt/'.$receipt['id'], [
            'lines' => [[
                'goods_receipt_line_id' => $receipt['lines'][0]['id'],
                'description' => $receipt['lines'][0]['description'],
                'quantity' => 1,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/returns/'.$return['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(1.0, (float) GoodsReceiptLine::query()->findOrFail($receipt['lines'][0]['id'])->returned_quantity);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_void_return(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();
        $bill = $this->postedBill($ctx);
        $return = $this->postJson('/api/purchase/returns/from-bill/'.$bill['id'], [], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/returns/'.$return['id'].'/void', ['reason' => 'Wrong return'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');
    }

    public function test_permission_denied_for_viewer(): void
    {
        $ctx = $this->setUpTenant('viewer');
        $this->postJson('/api/purchase/returns', $this->returnPayload($this->createVendor()), $ctx['headers'])->assertStatus(403);
    }

    private function postedBill(array $ctx): array
    {
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        return $this->getJson('/api/purchase/bills/'.$bill['id'], $ctx['headers'])->assertStatus(200)->json('data');
    }

    private function returnPayload(int $vendorId): array
    {
        return [
            'return_date' => '2026-05-20',
            'vendor_id' => $vendorId,
            'lines' => [['description' => 'Direct return', 'quantity' => 1, 'unit_price' => 10]],
        ];
    }
}
