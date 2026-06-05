<?php

namespace Tests\Feature\Purchase;

use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class PurchaseWorkflowIntegrationTest extends PurchaseTestCase
{
    public function test_full_purchase_chain_posts_vendor_bill_without_stock_movement(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();

        $request = $this->postJson('/api/purchase/requests', $this->purchaseRequestPayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/requests/'.$request['id'].'/submit', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/purchase/requests/'.$request['id'].'/approve', [], $ctx['headers'])->assertStatus(200);

        $order = $this->postJson('/api/purchase/orders/from-request/'.$request['id'], [
            'vendor_id' => $this->createVendor(),
            'order_date' => '2026-05-20',
            'lines' => [[
                'purchase_request_line_id' => $request['lines'][0]['id'],
                'description' => $request['lines'][0]['description'],
                'quantity' => 2,
                'unit_price' => 100,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $receipt = $this->postJson('/api/purchase/goods-receipts/from-purchase-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'].'/receive', [], $ctx['headers'])->assertStatus(200);
        $bill = $this->postJson('/api/purchase/bills/from-goods-receipt/'.$receipt['id'], [], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame($request['request_number'], $order['source_number']);
        $this->assertSame($receipt['receipt_number'], $bill['source_number']);
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'vendor_bill')->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertFalse(Schema::connection('tenant')->hasTable('inventory_valuations'));
    }

    public function test_po_vendor_deposit_bill_payment_return_and_ap_reconciliation(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();
        $vendorId = $this->createVendor();

        $order = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload([
            'vendor_id' => $vendorId,
            'has_down_payment' => true,
            'vendor_deposit' => [
                'deposit_date' => '2026-05-20',
                'cash_bank_account_id' => $accounts['cash'],
                'amount' => 50,
            ],
        ]), $ctx['headers'])->assertStatus(201)->json('data');
        $depositId = DB::connection('tenant')->table('vendor_deposits')->where('purchase_order_id', $order['id'])->value('id');
        $this->patchJson('/api/purchase/vendor-deposits/'.$depositId.'/post', [], $ctx['headers'])->assertStatus(200);

        $bill = $this->postJson('/api/purchase/bills/from-purchase-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $postedBill = $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.paid_amount', 50)
            ->json('data');

        $payment = $this->postJson('/api/purchase/payments', [
            'vendor_id' => $vendorId,
            'vendor_bill_id' => $postedBill['id'],
            'payment_date' => '2026-05-20',
            'cash_bank_account_id' => $accounts['cash'],
            'amount' => 50,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/payments/'.$payment['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $return = $this->postJson('/api/purchase/returns/from-bill/'.$postedBill['id'], [
            'lines' => [[
                'vendor_bill_line_id' => $postedBill['lines'][0]['id'],
                'description' => $postedBill['lines'][0]['description'],
                'quantity' => 1,
                'unit_price' => 100,
                'tax_amount' => 22,
                'line_total' => 122,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/returns/'.$return['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'vendor_deposit')->count());
        $this->assertSame(1, DB::connection('tenant')->table('vendor_deposit_allocations')->where('vendor_bill_id', $postedBill['id'])->count());
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'purchase_return')->count());

        $this->getJson('/api/purchase/ap/reconciliation', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_reconciled', true)
            ->assertJsonPath('data.subsidiary_balance', 0);
    }

    public function test_direct_bill_discount_and_goods_receipt_stock_rule(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload([
            'header_discount_type' => 'fixed_amount',
            'header_discount_value' => 20,
        ]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$bill['id'], [
            'bill_date' => '2026-05-20',
            'vendor_id' => $bill['vendor_id'],
            'header_discount_type' => 'fixed_amount',
            'header_discount_value' => 10,
            'lines' => $bill['lines'],
        ], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.header_discount_amount', 10);
        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $order = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload(['is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $receipt = $this->postJson('/api/purchase/goods-receipts/from-purchase-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'].'/receive', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(2.0, (float) PurchaseOrderLine::query()->findOrFail($order['lines'][0]['id'])->received_quantity);
        $this->assertSame(0.0, (float) GoodsReceiptLine::query()->findOrFail($receipt['lines'][0]['id'])->returned_quantity);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_purchase_routes_require_auth_company_and_permission(): void
    {
        $purchaseRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/purchase'))
            ->values();

        $this->assertGreaterThan(0, $purchaseRoutes->count());
        $purchaseRoutes->each(function ($route): void {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware);
            $this->assertContains('company.access', $middleware);
            $this->assertTrue(collect($middleware)->contains(fn (string $item): bool => str_starts_with($item, 'permission:')));
        });

        $this->getJson('/api/purchase/requests')->assertStatus(401);

        $ctx = $this->setUpTenant('viewer');
        $this->postJson('/api/purchase/requests', $this->purchaseRequestPayload(), $ctx['headers'])->assertStatus(403);
    }
}
