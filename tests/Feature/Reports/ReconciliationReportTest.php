<?php

namespace Tests\Feature\Reports;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\CustomerDeposit;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesReceipt;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\VendorDeposit;
use App\Models\Tenant\VendorPayment;
use App\Models\Tenant\Warehouse;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\Feature\Journal\JournalTestCase;

class ReconciliationReportTest extends JournalTestCase
{
    public function test_reconciliation_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/reports/reconciliation/inventory')
            ->assertStatus(401);
    }

    public function test_reconciliation_endpoint_returns_standard_response_structure(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $res = $this->getJson('/api/reports/reconciliation/inventory?date_to=2026-01-31', $ctx['headers'])
            ->assertStatus(200);

        $res->assertJsonPath('success', true);
        $this->assertIsArray($res->json('data'));
        $this->assertIsArray($res->json('data.summary'));
        $this->assertIsArray($res->json('data.data'));
        $this->assertIsArray($res->json('meta'));
    }

    public function test_ar_reconciliation_reports_matched_and_mismatched_customers(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cash = ChartOfAccount::query()->findOrFail($ctx['accounts']['debit']);
        $revenue = ChartOfAccount::query()->findOrFail($ctx['accounts']['credit']);
        $ar = $this->account('1100', 'Accounts Receivable', 'asset', 'debit');
        $this->mapping(AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE, $ar);

        $matched = $this->customer('CUST-001', 'Matched Customer');
        $mismatched = $this->customer('CUST-002', 'Mismatched Customer');

        $invoice1 = $this->salesInvoice('INV-001', $matched, $ar, 100, 40, 60);
        $this->journal('JV-AR-001', '2026-01-01', 'sales_invoice', $invoice1->id, [
            [$ar->id, 100, 0],
            [$revenue->id, 0, 100],
        ]);
        $receipt1 = $this->salesReceipt('RCPT-001', $matched, $invoice1, $cash, 40);
        $this->journal('JV-AR-002', '2026-01-05', 'sales_receipt', $receipt1->id, [
            [$cash->id, 40, 0],
            [$ar->id, 0, 40],
        ]);

        $invoice2 = $this->salesInvoice('INV-002', $mismatched, $ar, 100, 40, 60);
        $this->journal('JV-AR-003', '2026-01-01', 'sales_invoice', $invoice2->id, [
            [$ar->id, 100, 0],
            [$revenue->id, 0, 100],
        ]);
        $receipt2 = $this->salesReceipt('RCPT-002', $mismatched, $invoice2, $cash, 40);
        $this->journal('JV-AR-004', '2026-01-05', 'sales_receipt', $receipt2->id, [
            [$cash->id, 40, 0],
            [$ar->id, 0, 30],
        ]);

        $res = $this->getJson('/api/reports/reconciliation/ar?date_from=2026-01-01&date_to=2026-01-31', $ctx['headers'])
            ->assertStatus(200);

        $this->assertSame(1, $res->json('data.summary.mismatch_count'));
        $rows = collect($res->json('data.data'))->keyBy('customer_id');
        $this->assertSame('matched', $rows[$matched->id]['status']);
        $this->assertSame(60.0, (float) $rows[$matched->id]['subledger_ar_balance']);
        $this->assertSame(60.0, (float) $rows[$matched->id]['gl_ar_balance']);
        $this->assertSame('mismatch', $rows[$mismatched->id]['status']);
        $this->assertSame(60.0, (float) $rows[$mismatched->id]['subledger_ar_balance']);
        $this->assertSame(70.0, (float) $rows[$mismatched->id]['gl_ar_balance']);

        $diff = $this->getJson('/api/reports/reconciliation/ar?date_from=2026-01-01&date_to=2026-01-31&only_difference=true', $ctx['headers'])
            ->assertStatus(200);
        $this->assertCount(1, $diff->json('data.data'));
        $this->assertSame($mismatched->id, $diff->json('data.data.0.customer_id'));
    }

    public function test_reconciliation_date_range_filter_changes_response_rows(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cash = ChartOfAccount::query()->findOrFail($ctx['accounts']['debit']);
        $customer = $this->customer('CUST-DATE', 'Date Filter Customer');

        CustomerDeposit::query()->create([
            'deposit_number' => 'CD-JAN',
            'deposit_date' => '2026-01-10',
            'customer_id' => $customer->id,
            'cash_bank_account_id' => $cash->id,
            'amount' => 100,
            'allocated_amount' => 0,
            'remaining_amount' => 100,
            'status' => 'posted',
            'posted_at' => now(),
        ]);
        CustomerDeposit::query()->create([
            'deposit_number' => 'CD-FEB',
            'deposit_date' => '2026-02-10',
            'customer_id' => $customer->id,
            'cash_bank_account_id' => $cash->id,
            'amount' => 200,
            'allocated_amount' => 0,
            'remaining_amount' => 200,
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        $jan = $this->getJson('/api/reports/reconciliation/customer-deposits?date_from=2026-01-01&date_to=2026-01-31', $ctx['headers'])
            ->assertStatus(200);
        $feb = $this->getJson('/api/reports/reconciliation/customer-deposits?date_from=2026-02-01&date_to=2026-02-28', $ctx['headers'])
            ->assertStatus(200);

        $this->assertSame(100.0, (float) $jan->json('data.summary.total_deposit'));
        $this->assertSame(200.0, (float) $feb->json('data.summary.total_deposit'));
        $this->assertNotSame($jan->json('data.data.0.deposit_number'), $feb->json('data.data.0.deposit_number'));
    }

    public function test_ap_reconciliation_reports_matched_and_mismatched_vendors(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cash = ChartOfAccount::query()->findOrFail($ctx['accounts']['debit']);
        $expense = $this->account('5100', 'Expense', 'expense', 'debit');
        $ap = $this->account('2100', 'Accounts Payable', 'liability', 'credit');
        $this->mapping(AccountMappingKey::PURCHASE_ACCOUNTS_PAYABLE, $ap);

        $matched = $this->vendor('VEND-001', 'Matched Vendor');
        $mismatched = $this->vendor('VEND-002', 'Mismatched Vendor');

        $bill1 = $this->vendorBill('BILL-001', $matched, $ap, 100, 30, 70);
        $this->journal('JV-AP-001', '2026-01-01', 'vendor_bill', $bill1->id, [
            [$expense->id, 100, 0],
            [$ap->id, 0, 100],
        ]);
        $payment1 = $this->vendorPayment('VPAY-001', $matched, $bill1, $cash, 30);
        $this->journal('JV-AP-002', '2026-01-05', 'vendor_payment', $payment1->id, [
            [$ap->id, 30, 0],
            [$cash->id, 0, 30],
        ]);

        $bill2 = $this->vendorBill('BILL-002', $mismatched, $ap, 100, 30, 70);
        $this->journal('JV-AP-003', '2026-01-01', 'vendor_bill', $bill2->id, [
            [$expense->id, 100, 0],
            [$ap->id, 0, 100],
        ]);
        $payment2 = $this->vendorPayment('VPAY-002', $mismatched, $bill2, $cash, 30);
        $this->journal('JV-AP-004', '2026-01-05', 'vendor_payment', $payment2->id, [
            [$ap->id, 20, 0],
            [$cash->id, 0, 20],
        ]);

        $res = $this->getJson('/api/reports/reconciliation/ap?date_from=2026-01-01&date_to=2026-01-31', $ctx['headers'])
            ->assertStatus(200);

        $this->assertSame(1, $res->json('data.summary.mismatch_count'));
        $rows = collect($res->json('data.data'))->keyBy('vendor_id');
        $this->assertSame('matched', $rows[$matched->id]['status']);
        $this->assertSame(70.0, (float) $rows[$matched->id]['subledger_ap_balance']);
        $this->assertSame(70.0, (float) $rows[$matched->id]['gl_ap_balance']);
        $this->assertSame('mismatch', $rows[$mismatched->id]['status']);
        $this->assertSame(70.0, (float) $rows[$mismatched->id]['subledger_ap_balance']);
        $this->assertSame(80.0, (float) $rows[$mismatched->id]['gl_ap_balance']);
    }

    public function test_inventory_reconciliation_compares_stock_valuation_to_gl(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $capital = $this->account('3000', 'Capital', 'equity', 'credit');
        $inventory = $this->account('1200', 'Inventory', 'asset', 'debit');
        $this->mapping(AccountMappingKey::INVENTORY_ASSET, $inventory, 'inventory');
        $warehouse = Warehouse::query()->create(['code' => 'WH-01', 'name' => 'Main Warehouse', 'is_active' => true]);
        $product = Product::query()->create([
            'product_code' => 'ITEM-001',
            'product_name' => 'Stock Item',
            'product_type' => 'goods',
            'is_stock_item' => true,
            'is_active' => true,
            'inventory_account_id' => $inventory->id,
        ]);
        StockBalance::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity_on_hand' => 10,
            'quantity_available' => 10,
            'average_cost' => 50,
            'total_value' => 500,
        ]);
        $this->journal('JV-INV-001', '2026-01-01', 'opening_inventory', 1, [
            [$inventory->id, 450, 0],
            [$capital->id, 0, 450],
        ]);

        $res = $this->getJson('/api/reports/reconciliation/inventory?date_to=2026-01-31', $ctx['headers'])
            ->assertStatus(200);

        $this->assertSame(1, $res->json('data.summary.mismatch_count'));
        $this->assertSame(500.0, (float) $res->json('data.summary.total_valuation'));
        $this->assertSame(450.0, (float) $res->json('data.summary.total_gl_inventory'));
        $this->assertSame('mismatch', $res->json('data.data.0.status'));
    }

    public function test_inventory_reconciliation_matches_posted_stock_movement_to_gl(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $inventory = $this->account('1200', 'Inventory', 'asset', 'debit');
        $cogs = $this->account('5100', 'COGS', 'expense', 'debit');
        $equity = $this->account('3000', 'Opening Balance Equity', 'equity', 'credit');
        $this->mapping(AccountMappingKey::INVENTORY_ASSET, $inventory, 'inventory');
        $this->mapping(AccountMappingKey::INVENTORY_COGS, $cogs, 'inventory');
        $this->mapping(AccountMappingKey::OPENING_BALANCE_EQUITY, $equity, 'opening_balance');

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $warehouse = Warehouse::query()->create(['code' => 'WH-SM', 'name' => 'Stock Movement Warehouse', 'is_active' => true]);
        $product = Product::query()->create([
            'product_code' => 'SM-ITEM',
            'product_name' => 'Stock Movement Item',
            'product_type' => 'goods',
            'unit_id' => $unit->id,
            'is_stock_item' => true,
            'is_active' => true,
            'inventory_account_id' => $inventory->id,
        ]);

        $movement = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-10',
            'movement_type' => 'opening_stock',
            'lines' => [[
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'unit_id' => $unit->id,
                'quantity' => 5,
                'unit_cost' => 100,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/inventory/stock-movements/'.$movement['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200);

        $res = $this->getJson('/api/reports/reconciliation/inventory?date_to=2026-01-31', $ctx['headers'])
            ->assertStatus(200);

        $this->assertSame(500.0, (float) $res->json('data.summary.total_valuation'));
        $this->assertSame(500.0, (float) $res->json('data.summary.total_gl_inventory'));
        $this->assertSame(0.0, (float) $res->json('data.summary.total_difference'));
        $this->assertSame('matched', $res->json('data.data.0.status'));
    }

    public function test_grni_reconciliation_reports_outstanding_goods_receipt_against_interim_gl(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $inventory = $this->account('1200', 'Inventory', 'asset', 'debit');
        $interim = $this->account('2190', 'Inventory Interim', 'liability', 'credit');
        $this->mapping(AccountMappingKey::PURCHASE_INVENTORY_INTERIM, $interim, 'purchase');
        $vendor = $this->vendor('VEND-GRNI', 'GRNI Vendor');
        $warehouse = Warehouse::query()->create(['code' => 'WH-GRNI', 'name' => 'GRNI Warehouse', 'is_active' => true]);
        $product = Product::query()->create([
            'product_code' => 'GRNI-ITEM',
            'product_name' => 'GRNI Item',
            'product_type' => 'goods',
            'is_stock_item' => true,
            'is_active' => true,
            'inventory_account_id' => $inventory->id,
        ]);
        $po = PurchaseOrder::query()->create([
            'order_number' => 'PO-GRNI',
            'order_date' => '2026-01-01',
            'vendor_id' => $vendor->id,
            'status' => 'confirmed',
            'grand_total' => 50,
        ]);
        $poLine = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'description' => 'GRNI Item',
            'quantity' => 10,
            'unit_price' => 5,
            'line_total' => 50,
            'warehouse_id' => $warehouse->id,
        ]);
        $receipt = GoodsReceipt::query()->create([
            'receipt_number' => 'GR-GRNI',
            'receipt_date' => '2026-01-03',
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'received',
            'received_at' => now(),
        ]);
        GoodsReceiptLine::query()->create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $product->id,
            'description' => 'GRNI Item',
            'quantity' => 10,
            'billed_quantity' => 0,
            'warehouse_id' => $warehouse->id,
        ]);
        $movement = StockMovement::query()->create([
            'movement_number' => 'SM-GRNI',
            'movement_date' => '2026-01-03',
            'movement_type' => 'purchase_in',
            'direction' => 'in',
            'status' => 'posted',
            'source_type' => 'goods_receipt',
            'source_id' => $receipt->id,
            'source_number' => $receipt->receipt_number,
            'warehouse_id' => $warehouse->id,
            'total_quantity' => 10,
            'total_value' => 50,
            'posted_at' => now(),
        ]);
        $this->journal('JV-GRNI-001', '2026-01-03', 'stock_movement', $movement->id, [
            [$inventory->id, 50, 0],
            [$interim->id, 0, 50],
        ]);

        $res = $this->getJson('/api/reports/reconciliation/grni?date_to=2026-01-31', $ctx['headers'])
            ->assertStatus(200);

        $this->assertSame(0, $res->json('data.summary.mismatch_count'));
        $this->assertSame(10.0, (float) $res->json('data.summary.total_outstanding_quantity'));
        $this->assertSame(50.0, (float) $res->json('data.summary.total_estimated_outstanding_amount'));
        $this->assertSame(50.0, (float) $res->json('data.summary.total_grni_gl_balance_related'));
        $this->assertSame('matched', $res->json('data.data.0.status'));
    }

    public function test_unapplied_deposit_reports_return_remaining_amounts_and_contact_numbers(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cash = ChartOfAccount::query()->findOrFail($ctx['accounts']['debit']);
        $customer = $this->customer('CUST-DEP', 'Deposit Customer');
        $vendor = $this->vendor('VEND-DEP', 'Deposit Vendor');

        CustomerDeposit::query()->create([
            'deposit_number' => 'CD-001',
            'deposit_date' => '2026-01-07',
            'customer_id' => $customer->id,
            'cash_bank_account_id' => $cash->id,
            'amount' => 100,
            'allocated_amount' => 40,
            'remaining_amount' => 60,
            'status' => 'posted',
            'posted_at' => now(),
        ]);
        VendorDeposit::query()->create([
            'deposit_number' => 'VD-001',
            'deposit_date' => '2026-01-08',
            'vendor_id' => $vendor->id,
            'cash_bank_account_id' => $cash->id,
            'amount' => 200,
            'allocated_amount' => 50,
            'remaining_amount' => 150,
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        $customerRes = $this->getJson('/api/reports/reconciliation/customer-deposits?date_to=2026-01-31', $ctx['headers'])
            ->assertStatus(200);
        $this->assertSame(100.0, (float) $customerRes->json('data.summary.total_deposit'));
        $this->assertSame(60.0, (float) $customerRes->json('data.summary.total_unapplied'));
        $this->assertSame('CUST-DEP', $customerRes->json('data.data.0.customer_number'));

        $vendorRes = $this->getJson('/api/reports/reconciliation/vendor-deposits?date_to=2026-01-31', $ctx['headers'])
            ->assertStatus(200);
        $this->assertSame(200.0, (float) $vendorRes->json('data.summary.total_deposit'));
        $this->assertSame(150.0, (float) $vendorRes->json('data.summary.total_unapplied'));
        $this->assertSame('VEND-DEP', $vendorRes->json('data.data.0.vendor_number'));
    }

    private function account(string $code, string $name, string $type, string $normal): ChartOfAccount
    {
        return ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);
    }

    private function mapping(string $key, ChartOfAccount $account, string $module = 'sales'): AccountMapping
    {
        return AccountMapping::query()->create([
            'mapping_key' => $key,
            'module' => $module,
            'account_id' => $account->id,
            'is_required' => true,
            'is_active' => true,
        ]);
    }

    private function customer(string $code, string $name): Contact
    {
        return Contact::query()->create([
            'contact_code' => $code,
            'name' => $name,
            'contact_type' => 'customer',
            'is_customer' => true,
            'is_supplier' => false,
            'is_active' => true,
        ]);
    }

    private function vendor(string $code, string $name): Contact
    {
        return Contact::query()->create([
            'contact_code' => $code,
            'name' => $name,
            'contact_type' => 'supplier',
            'is_customer' => false,
            'is_supplier' => true,
            'is_active' => true,
        ]);
    }

    /**
     * @param array<int, array{0:int,1:float|int,2:float|int}> $lines
     */
    private function journal(string $number, string $date, string $sourceType, int $sourceId, array $lines): JournalEntry
    {
        $journal = JournalEntry::query()->create([
            'journal_number' => $number,
            'journal_date' => $date,
            'description' => $number,
            'status' => 'posted',
            'source_type' => $sourceType,
            'source_id' => (string) $sourceId,
            'is_system_generated' => true,
            'is_obsolete' => false,
            'posted_at' => now(),
        ]);

        foreach ($lines as $index => [$accountId, $debit, $credit]) {
            $journal->lines()->create([
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'line_order' => $index + 1,
            ]);
        }

        return $journal;
    }

    private function salesInvoice(string $number, Contact $customer, ChartOfAccount $ar, float $grandTotal, float $paid, float $balance): SalesInvoice
    {
        return SalesInvoice::query()->create([
            'invoice_number' => $number,
            'invoice_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'status' => 'posted',
            'grand_total' => $grandTotal,
            'paid_amount' => $paid,
            'balance_due' => $balance,
            'ar_account_id' => $ar->id,
            'posted_at' => now(),
        ]);
    }

    private function salesReceipt(string $number, Contact $customer, SalesInvoice $invoice, ChartOfAccount $cash, float $amount): SalesReceipt
    {
        return SalesReceipt::query()->create([
            'receipt_number' => $number,
            'receipt_date' => '2026-01-05',
            'customer_id' => $customer->id,
            'sales_invoice_id' => $invoice->id,
            'cash_bank_account_id' => $cash->id,
            'amount' => $amount,
            'status' => 'posted',
            'posted_at' => now(),
        ]);
    }

    private function vendorBill(string $number, Contact $vendor, ChartOfAccount $ap, float $grandTotal, float $paid, float $balance): VendorBill
    {
        return VendorBill::query()->create([
            'bill_number' => $number,
            'bill_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'vendor_id' => $vendor->id,
            'currency_code' => 'IDR',
            'status' => 'posted',
            'grand_total' => $grandTotal,
            'paid_amount' => $paid,
            'balance_due' => $balance,
            'ap_account_id' => $ap->id,
            'posted_at' => now(),
        ]);
    }

    private function vendorPayment(string $number, Contact $vendor, VendorBill $bill, ChartOfAccount $cash, float $amount): VendorPayment
    {
        return VendorPayment::query()->create([
            'payment_number' => $number,
            'payment_date' => '2026-01-05',
            'vendor_id' => $vendor->id,
            'vendor_bill_id' => $bill->id,
            'cash_bank_account_id' => $cash->id,
            'amount' => $amount,
            'status' => 'posted',
            'posted_at' => now(),
        ]);
    }
}
