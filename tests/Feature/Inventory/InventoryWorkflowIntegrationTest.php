<?php

namespace Tests\Feature\Inventory;

use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\DeliveryOrder;
use App\Models\Tenant\DeliveryOrderLine;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesInvoiceLine;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\VendorBillLine;
use App\Models\Tenant\Warehouse;
use App\Services\Purchase\GoodsReceiptService;
use App\Services\Purchase\PurchaseReturnService;
use App\Services\Purchase\VendorBillService;
use App\Services\Sales\DeliveryOrderService;
use App\Services\Sales\SalesInvoiceService;
use App\Services\Sales\SalesReturnService;
use App\Services\Tenant\TenantContext;
use App\Support\AccountMapping\AccountMappingKey;
use Illuminate\Support\Facades\Config;
use Tests\Feature\Journal\JournalTestCase;

class InventoryWorkflowIntegrationTest extends JournalTestCase
{
    public function test_purchase_to_stock_goods_receipt_creates_purchase_in_and_optional_interim_journal(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $this->setTenantContext($ctx);

        $this->seedInventoryMappings(includeInterim: true);
        $this->seedPurchaseMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $vendor = Contact::query()->create(['contact_code' => 'V1', 'name' => 'Vendor', 'contact_type' => 'company', 'is_customer' => false, 'is_supplier' => true, 'is_employee' => false, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $po = PurchaseOrder::query()->create([
            'order_number' => 'PO-001',
            'order_date' => '2026-01-01',
            'vendor_id' => $vendor->id,
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'status' => 'draft',
            'revision_no' => 1,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        $poLine = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $p->id,
            'product_code' => $p->product_code,
            'description' => 'Item',
            'quantity' => 10,
            'unit_id' => $unit->id,
            'unit_price' => 1000,
            'warehouse_id' => $wh->id,
            'sort_order' => 0,
        ]);

        $gr = GoodsReceipt::query()->create([
            'receipt_number' => 'GR-001',
            'receipt_date' => '2026-01-02',
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $po->id,
            'purchase_order_number' => $po->order_number,
            'status' => 'draft',
            'revision_no' => 1,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        GoodsReceiptLine::query()->create([
            'goods_receipt_id' => $gr->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $p->id,
            'product_code' => $p->product_code,
            'description' => 'Item',
            'quantity' => 5,
            'unit_id' => $unit->id,
            'warehouse_id' => $wh->id,
            'sort_order' => 0,
        ]);

        app(GoodsReceiptService::class)->receive($gr->refresh()->load('lines'));

        $movement = StockMovement::query()->where('source_type', 'goods_receipt')->where('source_id', $gr->id)->firstOrFail();
        $this->assertSame('purchase_in', (string) $movement->movement_type);
        $this->assertSame('posted', (string) $movement->status);

        $bal = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertSame(5.0, (float) $bal->quantity_on_hand);
        $this->assertSame(1000.0, (float) $bal->average_cost);

        $bill = app(VendorBillService::class)->createFromGoodsReceipt($gr->refresh()->load('lines'), [
            'bill_date' => '2026-01-03',
        ]);
        if ($bill->status !== 'posted') {
            app(VendorBillService::class)->post($bill->refresh()->load('lines'));
        }

        $this->assertSame(0, StockMovement::query()->where('source_type', 'vendor_bill')->where('source_id', $bill->id)->count());
        $bal->refresh();
        $this->assertSame(5.0, (float) $bal->quantity_on_hand);

        // Interim journal is optional, but when mapping exists it should be created.
        $journal = JournalEntry::query()->where('source_type', 'stock_movement')->where('source_id', $movement->id)->first();
        $this->assertNotNull($journal);
    }

    public function test_sales_delivery_creates_sales_out_and_sales_invoice_from_do_does_not_duplicate(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $this->setTenantContext($ctx);

        $this->seedInventoryMappings(includeInterim: false);
        $this->seedSalesMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $customer = Contact::query()->create(['contact_code' => 'C1', 'name' => 'Customer', 'contact_type' => 'person', 'is_customer' => true, 'is_supplier' => false, 'is_employee' => false, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        app(\App\Services\Inventory\StockMovementService::class)->createAndPost([
            'movement_date' => '2026-01-01',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 10, 'unit_cost' => 1000],
            ],
        ]);

        $do = DeliveryOrder::query()->create([
            'delivery_number' => 'DO-001',
            'delivery_date' => '2026-01-02',
            'customer_id' => $customer->id,
            'status' => 'draft',
            'revision_no' => 1,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        DeliveryOrderLine::query()->create([
            'delivery_order_id' => $do->id,
            'product_id' => $p->id,
            'product_code' => $p->product_code,
            'description' => 'Item',
            'quantity' => 2,
            'unit_id' => $unit->id,
            'warehouse_id' => $wh->id,
            'sort_order' => 0,
        ]);

        app(DeliveryOrderService::class)->deliver($do->refresh()->load('lines'));

        $this->assertSame(1, StockMovement::query()->where('source_type', 'delivery_order')->where('source_id', $do->id)->count());

        Config::set('inventory.allow_sales_invoice_direct_stock_issue', true);
        $inv = SalesInvoice::query()->create([
            'invoice_number' => 'INV-001',
            'invoice_date' => '2026-01-02',
            'due_date' => '2026-01-30',
            'customer_id' => $customer->id,
            'delivery_order_id' => $do->id,
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'status' => 'draft',
            'subtotal_before_discount' => 10000,
            'line_discount_total' => 0,
            'header_discount_amount' => 0,
            'subtotal_after_discount' => 10000,
            'tax_total' => 0,
            'grand_total' => 10000,
            'balance_due' => 10000,
            'revision_no' => 1,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        SalesInvoiceLine::query()->create([
            'sales_invoice_id' => $inv->id,
            'product_id' => $p->id,
            'product_code' => $p->product_code,
            'description' => 'Item',
            'quantity' => 2,
            'unit_id' => $unit->id,
            'unit_price' => 5000,
            'gross_amount' => 10000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'subtotal_after_discount' => 10000,
            'line_total' => 10000,
            'warehouse_id' => $wh->id,
            'sort_order' => 0,
        ]);

        app(SalesInvoiceService::class)->post($inv->refresh()->load('lines'));
        $this->assertSame(0, StockMovement::query()->where('source_type', 'sales_invoice')->where('source_id', $inv->id)->count());
    }

    public function test_vendor_bill_direct_creates_purchase_in_if_config_enabled(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $this->setTenantContext($ctx);

        $this->seedInventoryMappings(includeInterim: false);
        $this->seedPurchaseMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $vendor = Contact::query()->create(['contact_code' => 'V1', 'name' => 'Vendor', 'contact_type' => 'company', 'is_customer' => false, 'is_supplier' => true, 'is_employee' => false, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        Config::set('inventory.allow_vendor_bill_direct_stock_receipt', true);

        $bill = VendorBill::query()->create([
            'bill_number' => 'VB-001',
            'bill_date' => '2026-01-03',
            'vendor_id' => $vendor->id,
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'status' => 'draft',
            'subtotal_before_discount' => 10000,
            'line_discount_total' => 0,
            'header_discount_amount' => 0,
            'subtotal_after_discount' => 10000,
            'tax_total' => 0,
            'grand_total' => 10000,
            'balance_due' => 10000,
            'revision_no' => 1,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        VendorBillLine::query()->create([
            'vendor_bill_id' => $bill->id,
            'product_id' => $p->id,
            'product_code' => $p->product_code,
            'description' => 'Item',
            'quantity' => 5,
            'unit_id' => $unit->id,
            'unit_price' => 1000,
            'gross_amount' => 5000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'subtotal_after_discount' => 5000,
            'line_total' => 5000,
            'warehouse_id' => $wh->id,
            'sort_order' => 0,
        ]);

        if ($bill->status !== 'posted') {
            app(VendorBillService::class)->post($bill->refresh()->load('lines'));
        }
        $this->assertSame(1, StockMovement::query()->where('source_type', 'vendor_bill')->where('source_id', $bill->id)->count());
    }

    public function test_sales_invoice_direct_stock_issue_and_sales_return_creates_stock_in(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $this->setTenantContext($ctx);

        $this->seedInventoryMappings(includeInterim: false);
        $this->seedSalesMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $customer = Contact::query()->create(['contact_code' => 'C1', 'name' => 'Customer', 'contact_type' => 'company', 'is_customer' => true, 'is_supplier' => false, 'is_employee' => false, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        // Seed opening stock via stock movement endpoint (middleware sets tenant context & connection).
        $opening = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-01',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 10, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $this->patchJson('/api/inventory/stock-movements/'.((int) $opening->json('data.id')).'/post', [], $ctx['headers'])->assertStatus(200);

        $inv1 = SalesInvoice::query()->create([
            'invoice_number' => 'INV-001',
            'invoice_date' => '2026-01-02',
            'due_date' => '2026-01-30',
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'status' => 'draft',
            'subtotal_before_discount' => 5000,
            'line_discount_total' => 0,
            'header_discount_amount' => 0,
            'subtotal_after_discount' => 5000,
            'tax_total' => 0,
            'grand_total' => 5000,
            'balance_due' => 5000,
            'revision_no' => 1,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        SalesInvoiceLine::query()->create([
            'sales_invoice_id' => $inv1->id,
            'product_id' => $p->id,
            'product_code' => $p->product_code,
            'description' => 'Item',
            'quantity' => 1,
            'unit_id' => $unit->id,
            'unit_price' => 5000,
            'gross_amount' => 5000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'subtotal_after_discount' => 5000,
            'line_total' => 5000,
            'warehouse_id' => $wh->id,
            'sort_order' => 0,
        ]);
        app(SalesInvoiceService::class)->post($inv1->refresh()->load('lines'));
        $firstMovement = StockMovement::query()->where('source_type', 'sales_invoice')->where('source_id', $inv1->id)->firstOrFail();
        $this->assertSame('sales_out', (string) $firstMovement->movement_type);

        $inv2 = SalesInvoice::query()->create([
            'invoice_number' => 'INV-002',
            'invoice_date' => '2026-01-03',
            'due_date' => '2026-01-30',
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'status' => 'draft',
            'subtotal_before_discount' => 5000,
            'line_discount_total' => 0,
            'header_discount_amount' => 0,
            'subtotal_after_discount' => 5000,
            'tax_total' => 0,
            'grand_total' => 5000,
            'balance_due' => 5000,
            'revision_no' => 1,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        SalesInvoiceLine::query()->create([
            'sales_invoice_id' => $inv2->id,
            'product_id' => $p->id,
            'product_code' => $p->product_code,
            'description' => 'Item',
            'quantity' => 2,
            'unit_id' => $unit->id,
            'unit_price' => 2500,
            'gross_amount' => 5000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'subtotal_after_discount' => 5000,
            'line_total' => 5000,
            'warehouse_id' => $wh->id,
            'sort_order' => 0,
        ]);
        app(SalesInvoiceService::class)->post($inv2->refresh()->load('lines'));

        $movement = StockMovement::query()->where('source_type', 'sales_invoice')->where('source_id', $inv2->id)->firstOrFail();
        $this->assertSame('sales_out', (string) $movement->movement_type);
        $this->assertSame('posted', (string) $movement->status);

        $bal = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertSame(7.0, (float) $bal->quantity_on_hand);

        $return = app(SalesReturnService::class)->createFromSalesInvoice($inv2->refresh()->load('lines'), [
            'return_date' => '2026-01-04',
            'lines' => [[
                'sales_invoice_line_id' => SalesInvoiceLine::query()->where('sales_invoice_id', $inv2->id)->firstOrFail()->id,
                'product_id' => $p->id,
                'product_code' => $p->product_code,
                'description' => 'Return',
                'quantity' => 1,
                'unit_id' => $unit->id,
                'unit_price' => 2500,
                'line_total' => 2500,
                'warehouse_id' => $wh->id,
            ]],
        ]);
        if ($return->status !== 'posted') {
            app(SalesReturnService::class)->post($return);
        }

        $returnMovement = StockMovement::query()->where('source_type', 'sales_return')->where('source_id', $return->id)->firstOrFail();
        $this->assertSame('sales_return_in', (string) $returnMovement->movement_type);

        $bal->refresh();
        $this->assertSame(8.0, (float) $bal->quantity_on_hand);
    }

    public function test_purchase_return_creates_purchase_return_out_and_reduces_balance(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $this->setTenantContext($ctx);

        $this->seedInventoryMappings(includeInterim: true);
        $this->seedPurchaseMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $vendor = Contact::query()->create(['contact_code' => 'V1', 'name' => 'Vendor', 'contact_type' => 'company', 'is_customer' => false, 'is_supplier' => true, 'is_employee' => false, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $po = PurchaseOrder::query()->create([
            'order_number' => 'PO-001',
            'order_date' => '2026-01-01',
            'vendor_id' => $vendor->id,
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'status' => 'draft',
            'revision_no' => 1,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        $poLine = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $p->id,
            'product_code' => $p->product_code,
            'description' => 'Item',
            'quantity' => 10,
            'unit_id' => $unit->id,
            'unit_price' => 1000,
            'warehouse_id' => $wh->id,
            'sort_order' => 0,
        ]);

        $gr = GoodsReceipt::query()->create([
            'receipt_number' => 'GR-001',
            'receipt_date' => '2026-01-02',
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $po->id,
            'purchase_order_number' => $po->order_number,
            'status' => 'draft',
            'revision_no' => 1,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        GoodsReceiptLine::query()->create([
            'goods_receipt_id' => $gr->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $p->id,
            'product_code' => $p->product_code,
            'description' => 'Item',
            'quantity' => 5,
            'unit_id' => $unit->id,
            'warehouse_id' => $wh->id,
            'sort_order' => 0,
        ]);

        app(GoodsReceiptService::class)->receive($gr->refresh()->load('lines'));

        $bal = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertSame(5.0, (float) $bal->quantity_on_hand);

        $return = app(PurchaseReturnService::class)->createFromGoodsReceipt($gr->refresh()->load('lines'), [
            'return_date' => '2026-01-05',
            'lines' => [[
                'goods_receipt_line_id' => GoodsReceiptLine::query()->where('goods_receipt_id', $gr->id)->firstOrFail()->id,
                'product_id' => $p->id,
                'product_code' => $p->product_code,
                'description' => 'Return',
                'quantity' => 2,
                'unit_id' => $unit->id,
                'unit_price' => 0,
                'line_total' => 0,
                'warehouse_id' => $wh->id,
            ]],
        ]);
        if ($return->status !== 'posted') {
            app(PurchaseReturnService::class)->post($return);
        }

        $movement = StockMovement::query()->where('source_type', 'purchase_return')->where('source_id', $return->id)->firstOrFail();
        $this->assertSame('purchase_return_out', (string) $movement->movement_type);

        $bal->refresh();
        $this->assertSame(3.0, (float) $bal->quantity_on_hand);
    }

    public function test_stock_adjustment_and_stock_opname_create_movements_and_update_balances(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_approve_post',
            'auto_post_transactions' => false,
            'approval_enabled' => true,
        ]);
        $this->seedInventoryMappings(includeInterim: false);

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $adj = $this->postJson('/api/inventory/stock-adjustments', [
            'adjustment_date' => '2026-01-10',
            'reason' => 'Init',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'adjustment_type' => 'increase', 'quantity' => 10, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $adjId = (int) $adj->json('data.id');
        $this->patchJson('/api/inventory/stock-adjustments/'.$adjId.'/approve', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/inventory/stock-adjustments/'.$adjId.'/post', [], $ctx['headers'])->assertStatus(200);

        $bal = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertSame(10.0, (float) $bal->quantity_on_hand);

        $op = $this->postJson('/api/inventory/stock-opnames', [
            'opname_date' => '2026-01-15',
            'warehouse_id' => $wh->id,
        ], $ctx['headers'])->assertStatus(201);
        $opId = (int) $op->json('data.id');
        $this->postJson('/api/inventory/stock-opnames/'.$opId.'/generate-lines', [], $ctx['headers'])->assertStatus(200);

        $line = \App\Models\Tenant\StockOpnameLine::query()->where('stock_opname_id', $opId)->firstOrFail();
        $this->patchJson('/api/inventory/stock-opnames/'.$opId.'/lines/'.$line->id, [
            'physical_quantity' => 8,
            'reason' => 'Shrinkage',
        ], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/inventory/stock-opnames/'.$opId.'/counted', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/inventory/stock-opnames/'.$opId.'/finalize', [], $ctx['headers'])->assertStatus(200);

        $bal->refresh();
        $this->assertSame(8.0, (float) $bal->quantity_on_hand);
    }

    private function setTenantContext(array $ctx): void
    {
        $companyUser = CompanyUser::query()->where('company_id', $ctx['company']->id)->where('user_id', $ctx['user']->id)->firstOrFail();
        $tenantDb = TenantDatabase::query()->where('company_id', $ctx['company']->id)->firstOrFail();
        app(TenantContext::class)->set($ctx['company'], $companyUser, $tenantDb);
    }

    private function seedInventoryMappings(bool $includeInterim): void
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

        if ($includeInterim) {
            $interim = ChartOfAccount::query()->create(['account_code' => '2150', 'account_name' => 'Inventory Interim', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
            AccountMapping::query()->create(['mapping_key' => AccountMappingKey::PURCHASE_INVENTORY_INTERIM, 'module' => 'purchase', 'account_id' => $interim->id, 'is_active' => true]);
        }
    }

    private function seedPurchaseMappings(): void
    {
        $expense = ChartOfAccount::query()->create(['account_code' => '6000', 'account_name' => 'Expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $ap = ChartOfAccount::query()->create(['account_code' => '2100', 'account_name' => 'AP', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        AccountMapping::query()->create(['mapping_key' => 'purchase.expense', 'module' => 'purchase', 'account_id' => $expense->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => 'purchase.accounts_payable', 'module' => 'purchase', 'account_id' => $ap->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => 'purchase.return', 'module' => 'purchase', 'account_id' => $expense->id, 'is_active' => true]);
    }

    private function seedSalesMappings(): void
    {
        $ar = ChartOfAccount::query()->create(['account_code' => '1100', 'account_name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $rev = ChartOfAccount::query()->create(['account_code' => '4001', 'account_name' => 'Sales', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        AccountMapping::query()->create(['mapping_key' => 'sales.accounts_receivable', 'module' => 'sales', 'account_id' => $ar->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => 'sales.revenue', 'module' => 'sales', 'account_id' => $rev->id, 'is_active' => true]);
        $sr = ChartOfAccount::query()->create(['account_code' => '4200', 'account_name' => 'Sales Return', 'account_type' => 'revenue', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        AccountMapping::query()->create(['mapping_key' => 'sales.return', 'module' => 'sales', 'account_id' => $sr->id, 'is_active' => true]);
    }
}
