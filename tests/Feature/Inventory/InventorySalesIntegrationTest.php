<?php

namespace Tests\Feature\Inventory;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\DeliveryOrder;
use App\Models\Tenant\DeliveryOrderLine;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesInvoiceLine;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\SalesReturnLine;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Services\Sales\DeliveryOrderService;
use App\Services\Sales\SalesInvoiceService;
use App\Services\Sales\SalesReturnService;
use App\Support\AccountMapping\AccountMappingKey;
use Illuminate\Support\Facades\Config;
use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Services\Tenant\TenantContext;
use Tests\Feature\Journal\JournalTestCase;

class InventorySalesIntegrationTest extends JournalTestCase
{
    public function test_delivery_order_delivered_creates_sales_out_once_and_sales_invoice_from_do_does_not_duplicate(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $companyUser = CompanyUser::query()->where('company_id', $ctx['company']->id)->where('user_id', $ctx['user']->id)->firstOrFail();
        $tenantDb = TenantDatabase::query()->where('company_id', $ctx['company']->id)->firstOrFail();
        app(TenantContext::class)->set($ctx['company'], $companyUser, $tenantDb);
        $this->seedInventoryMappings();
        $this->seedSalesMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $customer = Contact::query()->create(['contact_code' => 'C1', 'name' => 'Customer', 'contact_type' => 'person', 'is_customer' => true, 'is_supplier' => false, 'is_employee' => false, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        // opening stock
        $open = app(\App\Services\Inventory\StockMovementService::class)->createAndPost([
            'movement_date' => '2026-01-01',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 10, 'unit_cost' => 1000],
            ],
        ]);

        $this->assertSame('posted', $open->status);
        $this->assertSame(10.0, (float) StockBalance::query()->firstOrFail()->quantity_on_hand);

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

        $svc = app(DeliveryOrderService::class);
        $svc->deliver($do->refresh()->load('lines'));

        $movement = StockMovement::query()->where('source_type', 'delivery_order')->where('source_id', $do->id)->firstOrFail();
        $this->assertSame('sales_out', (string) $movement->movement_type);
        $this->assertSame('posted', (string) $movement->status);

        $bal = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertSame(8.0, (float) $bal->quantity_on_hand);

        // deliver again should fail and not duplicate
        try {
            $svc->deliver($do->refresh()->load('lines'));
            $this->fail('Expected double delivery to fail.');
        } catch (ApiException $e) {
            $this->assertSame('DOCUMENT_ALREADY_POSTED', $e->codeName);
        }
        $this->assertSame(1, StockMovement::query()->where('source_type', 'delivery_order')->where('source_id', $do->id)->count());

        // sales invoice from delivery order should not create sales_invoice stock movement
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
            'applied_down_payment_amount' => 0,
            'paid_amount' => 0,
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

    public function test_sales_return_posted_creates_sales_return_in_movement(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $companyUser = CompanyUser::query()->where('company_id', $ctx['company']->id)->where('user_id', $ctx['user']->id)->firstOrFail();
        $tenantDb = TenantDatabase::query()->where('company_id', $ctx['company']->id)->firstOrFail();
        app(TenantContext::class)->set($ctx['company'], $companyUser, $tenantDb);
        $this->seedInventoryMappings();
        $this->seedSalesMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $customer = Contact::query()->create(['contact_code' => 'C1', 'name' => 'Customer', 'contact_type' => 'person', 'is_customer' => true, 'is_supplier' => false, 'is_employee' => false, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        app(\App\Services\Inventory\StockMovementService::class)->createAndPost([
            'movement_date' => '2026-01-01',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 1, 'unit_cost' => 1000],
            ],
        ]);

        $ret = SalesReturn::query()->create([
            'return_number' => 'SR-001',
            'return_date' => '2026-01-03',
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'status' => 'draft',
            'subtotal_before_discount' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
            'revision_no' => 1,
            'created_by' => auth()->id(),
        ]);
        SalesReturnLine::query()->create([
            'sales_return_id' => $ret->id,
            'product_id' => $p->id,
            'product_code' => $p->product_code,
            'description' => 'Return',
            'quantity' => 1,
            'unit_id' => $unit->id,
            'unit_price' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 0,
            'warehouse_id' => $wh->id,
            'sort_order' => 0,
        ]);

        app(SalesReturnService::class)->post($ret->refresh()->load('lines'));

        $movement = StockMovement::query()->where('source_type', 'sales_return')->where('source_id', $ret->id)->firstOrFail();
        $this->assertSame('sales_return_in', (string) $movement->movement_type);
        $this->assertSame('posted', (string) $movement->status);
    }

    private function seedInventoryMappings(): void
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
