<?php

namespace Tests\Feature\Inventory;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\PurchaseReturnLine;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\VendorBillLine;
use App\Models\Tenant\Warehouse;
use App\Services\Purchase\GoodsReceiptService;
use App\Services\Purchase\PurchaseReturnService;
use App\Services\Purchase\VendorBillService;
use App\Support\AccountMapping\AccountMappingKey;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Services\Tenant\TenantContext;
use Tests\Feature\Journal\JournalTestCase;

class InventoryPurchaseIntegrationTest extends JournalTestCase
{
    public function test_goods_receipt_received_creates_purchase_in_and_updates_average_cost(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $companyUser = CompanyUser::query()->where('company_id', $ctx['company']->id)->where('user_id', $ctx['user']->id)->firstOrFail();
        $tenantDb = TenantDatabase::query()->where('company_id', $ctx['company']->id)->firstOrFail();
        app(TenantContext::class)->set($ctx['company'], $companyUser, $tenantDb);
        $this->seedInventoryMappings();
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

        $line = $movement->lines()->firstOrFail();
        $this->assertSame(1000.0, (float) $line->unit_cost);
        $this->assertNotNull($line->inventory_account_id);

        $journalLines = DB::connection('tenant')
            ->table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.source_type', 'stock_movement')
            ->where('journal_entries.source_id', $movement->id)
            ->orderBy('journal_entry_lines.line_order')
            ->get(['journal_entry_lines.description', 'journal_entry_lines.debit', 'journal_entry_lines.credit']);
        $this->assertSame('Inventory', $journalLines[0]->description);
        $this->assertSame(5000.0, (float) $journalLines[0]->debit);
        $this->assertSame('Inventory Interim', $journalLines[1]->description);
        $this->assertSame(5000.0, (float) $journalLines[1]->credit);

        $bal = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertSame(5.0, (float) $bal->quantity_on_hand);
        $this->assertSame(1000.0, (float) $bal->average_cost);

        $bill = app(VendorBillService::class)->createFromGoodsReceipt($gr->refresh()->load('lines'), ['bill_date' => '2026-01-03']);
        app(VendorBillService::class)->post($bill->refresh()->load('lines'));
        $billJournalLines = DB::connection('tenant')
            ->table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.source_type', 'vendor_bill')
            ->where('journal_entries.source_id', $bill->id)
            ->orderBy('journal_entry_lines.line_order')
            ->get(['journal_entry_lines.description', 'journal_entry_lines.debit', 'journal_entry_lines.credit']);
        $this->assertSame('Inventory Interim', $billJournalLines[0]->description);
        $this->assertSame(5000.0, (float) $billJournalLines[0]->debit);
        $this->assertSame('Accounts Payable', $billJournalLines[1]->description);
        $this->assertSame(5000.0, (float) $billJournalLines[1]->credit);
    }

    public function test_goods_receipt_stock_item_requires_inventory_interim_mapping(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $companyUser = CompanyUser::query()->where('company_id', $ctx['company']->id)->where('user_id', $ctx['user']->id)->firstOrFail();
        $tenantDb = TenantDatabase::query()->where('company_id', $ctx['company']->id)->firstOrFail();
        app(TenantContext::class)->set($ctx['company'], $companyUser, $tenantDb);
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $vendor = Contact::query()->create(['contact_code' => 'V1', 'name' => 'Vendor', 'contact_type' => 'company', 'is_customer' => false, 'is_supplier' => true, 'is_employee' => false, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $gr = GoodsReceipt::query()->create([
            'receipt_number' => 'GR-001',
            'receipt_date' => '2026-01-02',
            'vendor_id' => $vendor->id,
            'status' => 'draft',
            'revision_no' => 1,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        GoodsReceiptLine::query()->create([
            'goods_receipt_id' => $gr->id,
            'product_id' => $p->id,
            'product_code' => $p->product_code,
            'description' => 'Item',
            'quantity' => 5,
            'unit_id' => $unit->id,
            'warehouse_id' => $wh->id,
            'sort_order' => 0,
        ]);

        $this->expectExceptionMessage('Akun Inventory Interim/GRNI belum diatur.');
        app(GoodsReceiptService::class)->receive($gr->refresh()->load('lines'));
    }

    public function test_vendor_bill_direct_creates_purchase_in_when_config_enabled_and_purchase_return_posted_creates_purchase_return_out(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $companyUser = CompanyUser::query()->where('company_id', $ctx['company']->id)->where('user_id', $ctx['user']->id)->firstOrFail();
        $tenantDb = TenantDatabase::query()->where('company_id', $ctx['company']->id)->firstOrFail();
        app(TenantContext::class)->set($ctx['company'], $companyUser, $tenantDb);
        $this->seedInventoryMappings();
        $this->seedPurchaseMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $vendor = Contact::query()->create(['contact_code' => 'V1', 'name' => 'Vendor', 'contact_type' => 'company', 'is_customer' => false, 'is_supplier' => true, 'is_employee' => false, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        Config::set('inventory.allow_vendor_bill_direct_stock_receipt', true);

        $bill = VendorBill::query()->create([
            'bill_number' => 'BILL-001',
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
            'applied_vendor_deposit_amount' => 0,
            'paid_amount' => 0,
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

        app(VendorBillService::class)->post($bill->refresh()->load('lines'));

        $movement = StockMovement::query()->where('source_type', 'vendor_bill')->where('source_id', $bill->id)->firstOrFail();
        $this->assertSame('purchase_in', (string) $movement->movement_type);
        $this->assertSame('posted', (string) $movement->status);
        $this->assertNull($movement->journal_entry_id);
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'vendor_bill')->where('source_id', $bill->id)->count());

        $ret = PurchaseReturn::query()->create([
            'return_number' => 'PR-001',
            'return_date' => '2026-01-04',
            'vendor_id' => $vendor->id,
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
        PurchaseReturnLine::query()->create([
            'purchase_return_id' => $ret->id,
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

        app(PurchaseReturnService::class)->post($ret->refresh()->load('lines'));
        $out = StockMovement::query()->where('source_type', 'purchase_return')->where('source_id', $ret->id)->firstOrFail();
        $this->assertSame('purchase_return_out', (string) $out->movement_type);
        $this->assertSame('posted', (string) $out->status);
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

    private function seedPurchaseMappings(): void
    {
        $expense = ChartOfAccount::query()->create(['account_code' => '6000', 'account_name' => 'Expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $ap = ChartOfAccount::query()->create(['account_code' => '2100', 'account_name' => 'AP', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $interim = ChartOfAccount::query()->create(['account_code' => '2150', 'account_name' => 'Inventory Interim', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        AccountMapping::query()->create(['mapping_key' => 'purchase.expense', 'module' => 'purchase', 'account_id' => $expense->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => 'purchase.accounts_payable', 'module' => 'purchase', 'account_id' => $ap->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => 'purchase.inventory_interim', 'module' => 'purchase', 'account_id' => $interim->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => 'purchase.return', 'module' => 'purchase', 'account_id' => $expense->id, 'is_active' => true]);
    }
}
