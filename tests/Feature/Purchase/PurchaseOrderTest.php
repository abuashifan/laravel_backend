<?php

namespace Tests\Feature\Purchase;

use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseRequest;
use App\Models\Tenant\VendorDeposit;
use App\Models\Tenant\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseOrderTest extends PurchaseTestCase
{
    public function test_can_create_purchase_order_directly(): void
    {
        $ctx = $this->setUpTenant();
        $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload(), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.grand_total', 222);

        $this->assertDatabaseCount('purchase_orders', 1, 'tenant');
        $this->assertDatabaseCount('purchase_order_lines', 1, 'tenant');
    }

    public function test_can_create_purchase_order_from_purchase_request(): void
    {
        $ctx = $this->setUpTenant();
        $request = $this->postJson('/api/purchase/requests', $this->purchaseRequestPayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/requests/'.$request['id'].'/submit', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/purchase/requests/'.$request['id'].'/approve', [], $ctx['headers'])->assertStatus(200);

        $order = $this->postJson('/api/purchase/orders/from-request/'.$request['id'], ['vendor_id' => $this->createVendor()], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.purchase_request_id', $request['id'])
            ->json('data');

        $this->assertSame('converted', PurchaseRequest::query()->find($request['id'])->status);
        $this->assertNotNull($order['lines'][0]['purchase_request_line_id']);
    }

    public function test_purchase_request_id_nullable_and_down_payment_false_works(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload([
            'purchase_request_id' => null,
            'has_down_payment' => false,
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $this->assertNull($order['purchase_request_id']);
        $this->assertFalse((bool) $order['has_down_payment']);
    }

    public function test_has_down_payment_true_without_deposit_payload_works(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload([
            'has_down_payment' => true,
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.has_down_payment', true);

        $this->assertDatabaseCount('vendor_deposits', 0, 'tenant');
    }

    public function test_has_down_payment_true_with_deposit_payload_creates_vendor_deposit(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->cashBankAccount();

        $order = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload([
            'has_down_payment' => true,
            'vendor_deposit' => [
                'deposit_date' => '2026-05-20',
                'cash_bank_account_id' => $cash,
                'amount' => 50,
                'notes' => 'DP vendor',
            ],
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $this->assertDatabaseHas('vendor_deposits', [
            'purchase_order_id' => $order['id'],
            'vendor_id' => $order['vendor_id'],
            'status' => 'draft',
        ], 'tenant');
    }

    public function test_vendor_deposit_does_not_live_as_direct_purchase_order_payment_field(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->cashBankAccount();
        $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload([
            'has_down_payment' => true,
            'vendor_deposit' => [
                'deposit_date' => '2026-05-20',
                'cash_bank_account_id' => $cash,
                'amount' => 50,
            ],
        ]), $ctx['headers'])->assertStatus(201);

        $this->assertTrue(Schema::connection('tenant')->hasTable('vendor_deposits'));
        $this->assertFalse(Schema::connection('tenant')->hasColumn('purchase_orders', 'down_payment_amount'));
    }

    private function cashBankAccount(): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => 'CASH-'.uniqid(),
            'account_name' => 'Cash',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => true,
            'is_active' => true,
        ])->id;
    }

    public function test_purchase_order_does_not_create_ap_journal_or_stock_movement(): void
    {
        $ctx = $this->setUpTenant();
        $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload(), $ctx['headers'])->assertStatus(201);

        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_discount_percent_and_fixed_work(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload([
            'is_taxable' => false,
            'header_discount_type' => 'percent',
            'header_discount_value' => 10,
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.header_discount_amount', 20);

        $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload([
            'is_taxable' => false,
            'lines' => [['description' => 'Line', 'quantity' => 2, 'unit_price' => 100, 'discount_type' => 'fixed_amount', 'discount_value' => 25]],
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.line_discount_total', 25);
    }

    public function test_confirm_and_cancel_purchase_order(): void
    {
        $ctx = $this->setUpTenant();

        $confirmed = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/orders/'.$confirmed['id'].'/confirm', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed');

        $cancelled = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/orders/'.$cancelled['id'].'/cancel', ['reason' => 'Vendor cancelled'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_tenant_isolation(): void
    {
        $ctxA = $this->setUpTenant();
        $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload(), $ctxA['headers'])->assertStatus(201);

        $ctxB = $this->setUpTenant();
        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertSame(0, VendorDeposit::query()->count());

        $this->getJson('/api/purchase/orders', $ctxB['headers'])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_permission_denied_for_viewer(): void
    {
        $ctx = $this->setUpTenant('viewer');

        $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload(), $ctx['headers'])->assertStatus(403);
    }
}
