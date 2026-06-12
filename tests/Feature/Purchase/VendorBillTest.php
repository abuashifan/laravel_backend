<?php

namespace Tests\Feature\Purchase;

use App\Models\CompanyAccountingSetting;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class VendorBillTest extends PurchaseTestCase
{
    public function test_create_bill_draft_without_payable_mapping(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['is_taxable' => false]), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_inactive_vendor_is_rejected(): void
    {
        $ctx = $this->setUpTenant();
        $vendorId = $this->createVendor(['is_active' => false]);

        $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['vendor_id' => $vendorId]), $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VENDOR_NOT_VALID');
    }

    public function test_simple_auto_post_without_approval_posts_bill_on_create(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();
        CompanyAccountingSetting::query()->where('company_id', $ctx['company']->id)->update([
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
            'approval_enabled' => false,
        ]);

        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.grand_total', 222)
            ->json('data');

        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'vendor_bill')->where('source_id', $bill['id'])->count());
        $this->assertNotNull(DB::connection('tenant')->table('vendor_bills')->where('id', $bill['id'])->value('posted_at'));
    }

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

    public function test_direct_stock_bill_requires_warehouse_before_posting(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();
        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $productId = Product::query()->create([
            'product_code' => 'STK-BILL',
            'product_name' => 'Stock Bill',
            'product_type' => 'goods',
            'unit_id' => $unit->id,
            'is_stock_item' => true,
            'is_active' => true,
        ])->id;

        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload([
            'is_taxable' => false,
            'lines' => [['product_id' => $productId, 'description' => 'Stock Bill', 'quantity' => 1, 'unit_price' => 100]],
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'WAREHOUSE_REQUIRED');
    }

    public function test_post_bill_fails_with_actionable_message_when_payable_account_is_missing(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings(payable: false, legacyPayable: false);
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ACCOUNT_MAPPING_MISSING')
            ->assertJsonPath('message', 'Akun Hutang Usaha belum diatur. Buka Pengaturan > Pemetaan Akun > Purchase > Hutang Usaha.');
    }

    public function test_post_bill_uses_transaction_payable_account_snapshot(): void
    {
        $ctx = $this->setUpTenant();
        $selectedAp = $this->createAccount('liability', 'APT-'.uniqid());
        $this->seedPurchaseMappings(payable: false, legacyPayable: false);

        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload([
            'ap_account_id' => $selectedAp,
            'is_taxable' => false,
        ]), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.ap_account_id', $selectedAp)
            ->json('data');

        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.ap_account_id', $selectedAp);

        $this->assertSame($selectedAp, (int) DB::connection('tenant')
            ->table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.source_type', 'vendor_bill')
            ->where('journal_entries.source_id', $bill['id'])
            ->where('journal_entry_lines.credit', '>', 0)
            ->value('journal_entry_lines.account_id'));
    }

    public function test_create_bill_rejects_non_liability_payable_account(): void
    {
        $ctx = $this->setUpTenant();
        $assetAccount = $this->createAccount('asset', 'NOTAP-'.uniqid());

        $this->postJson('/api/purchase/bills', $this->vendorBillPayload([
            'ap_account_id' => $assetAccount,
        ]), $ctx['headers'])->assertStatus(422);
    }

    public function test_post_bill_ignores_deprecated_vendor_payable_account(): void
    {
        $ctx = $this->setUpTenant();
        $vendorAp = $this->createAccount('liability', 'APV-'.uniqid());
        $defaultIds = $this->seedPurchaseMappings();
        $vendorId = $this->createVendor(['payable_account_id' => $vendorAp]);
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['vendor_id' => $vendorId, 'is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.ap_account_id', $defaultIds['ap']);

        $this->assertSame($defaultIds['ap'], (int) DB::connection('tenant')->table('vendor_bills')->where('id', $bill['id'])->value('ap_account_id'));
        $this->assertSame($defaultIds['ap'], (int) DB::connection('tenant')
            ->table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.source_type', 'vendor_bill')
            ->where('journal_entries.source_id', $bill['id'])
            ->where('journal_entry_lines.credit', '>', 0)
            ->value('journal_entry_lines.account_id'));
    }

    public function test_post_bill_uses_default_and_legacy_payable_mapping_fallbacks(): void
    {
        $ctx = $this->setUpTenant();
        $defaultIds = $this->seedPurchaseMappings();
        $defaultBill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$defaultBill['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.ap_account_id', $defaultIds['ap']);

        $ctx = $this->setUpTenant();
        $legacyIds = $this->seedPurchaseMappings(payable: false, legacyPayable: true);
        $legacyBill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$legacyBill['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.ap_account_id', $legacyIds['ap']);
    }

    public function test_backfill_vendor_bill_account_snapshots_supports_dry_run_and_execute(): void
    {
        $ctx = $this->setUpTenant();
        $ids = $this->seedPurchaseMappings();
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        DB::connection('tenant')->table('vendor_bills')->where('id', $bill['id'])->update(['ap_account_id' => null]);
        DB::connection('tenant')->table('vendor_bill_lines')->where('vendor_bill_id', $bill['id'])->update(['expense_account_id' => null]);

        Artisan::call('tenant:backfill-vendor-bill-account-snapshots', ['--company-id' => $ctx['company']->id]);
        app(TenantConnectionManager::class)->connect($ctx['tenant_path']);
        $this->assertNull(DB::connection('tenant')->table('vendor_bills')->where('id', $bill['id'])->value('ap_account_id'));
        $this->assertNull(DB::connection('tenant')->table('vendor_bill_lines')->where('vendor_bill_id', $bill['id'])->value('expense_account_id'));

        Artisan::call('tenant:backfill-vendor-bill-account-snapshots', ['--company-id' => $ctx['company']->id, '--execute' => true]);
        app(TenantConnectionManager::class)->connect($ctx['tenant_path']);
        $this->assertSame($ids['ap'], (int) DB::connection('tenant')->table('vendor_bills')->where('id', $bill['id'])->value('ap_account_id'));
        $this->assertSame($ids['expense'], (int) DB::connection('tenant')->table('vendor_bill_lines')->where('vendor_bill_id', $bill['id'])->value('expense_account_id'));
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
