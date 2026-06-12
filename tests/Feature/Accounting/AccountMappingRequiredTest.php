<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\TenantTestCase;

/**
 * M2 — Preflight account mapping validation.
 *
 * Each test verifies that attempting to post a document when a conditional-required
 * mapping is missing returns 422 MAPPING_REQUIRED before any DB transaction work.
 */
class AccountMappingRequiredTest extends TenantTestCase
{
    protected Contact $customer;
    protected Contact $vendor;
    protected Product $stockProduct;
    protected Warehouse $warehouse;
    protected Unit $unit;

    /** @var array<string,int> */
    protected array $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer     = Contact::factory()->customer()->create(['name' => 'M2 Customer '.uniqid()]);
        $this->vendor       = Contact::factory()->vendor()->create(['name' => 'M2 Vendor '.uniqid()]);
        $this->unit         = Unit::query()->create(['name' => 'pcs', 'code' => 'pcs', 'symbol' => 'pcs', 'is_active' => true]);
        $this->stockProduct = Product::factory()->stockItem()->create(['product_name' => 'M2 Stock Product '.uniqid(), 'product_code' => 'M2-'.uniqid(), 'unit_id' => $this->unit->id]);
        $this->warehouse    = Warehouse::factory()->create(['name' => 'M2 Warehouse '.uniqid()]);

        $this->accounts = $this->seedAllMappings();
    }

    protected function tenantRole(): string
    {
        return 'owner';
    }

    protected function accountingSettingOverrides(): array
    {
        return [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions'    => false,
            'approval_enabled'          => false,
        ];
    }

    // -------------------------------------------------------------------------
    // Test 1 — GoodsReceipt receive without purchase.inventory_interim
    // -------------------------------------------------------------------------

    public function test_goods_receipt_receive_without_inventory_interim_mapping_returns_422(): void
    {
        $gr = $this->postJson('/api/purchase/goods-receipts', [
            'vendor_id'    => $this->vendor->id,
            'receipt_date' => '2026-05-01',
            'lines'        => [[
                'product_id'   => $this->stockProduct->id,
                'description'  => 'Stock item',
                'quantity'     => 5,
                'unit_price'   => 100,
                'warehouse_id' => $this->warehouse->id,
            ]],
        ], $this->headers)->assertCreated()->json('data');

        AccountMapping::query()->where('mapping_key', AccountMappingKey::PURCHASE_INVENTORY_INTERIM)
            ->update(['account_id' => null]);

        $this->patchJson('/api/purchase/goods-receipts/'.$gr['id'].'/receive', [], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'MAPPING_REQUIRED');
    }

    // -------------------------------------------------------------------------
    // Test 2 — SalesReturn post without sales.return
    // -------------------------------------------------------------------------

    public function test_sales_return_post_without_sales_return_mapping_returns_422(): void
    {
        $invoice = $this->postAndPostInvoice(500.0, false);
        $return  = $this->postJson('/api/sales/returns', [
            'customer_id' => $this->customer->id,
            'return_date' => '2026-05-02',
            'sales_invoice_id' => $invoice['id'],
            'lines' => [[
                'sales_invoice_line_id' => $invoice['lines'][0]['id'],
                'description'  => (string) $invoice['lines'][0]['description'],
                'quantity'     => 1,
                'unit_price'   => 50.0,
                'discount_amount' => 0,
                'tax_amount'   => 0,
                'line_total'   => 50.0,
            ]],
        ], $this->headers)->assertCreated()->json('data');

        AccountMapping::query()->where('mapping_key', AccountMappingKey::SALES_RETURN)
            ->update(['account_id' => null]);

        $this->patchJson('/api/sales/returns/'.$return['id'].'/post', [], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'MAPPING_REQUIRED');
    }

    // -------------------------------------------------------------------------
    // Test 3 — PurchaseReturn post without purchase.return
    // -------------------------------------------------------------------------

    public function test_purchase_return_post_without_purchase_return_mapping_returns_422(): void
    {
        $bill   = $this->postAndPostBill(500.0, false);
        $return = $this->postJson('/api/purchase/returns', [
            'vendor_id'     => $this->vendor->id,
            'return_date'   => '2026-05-02',
            'vendor_bill_id' => $bill['id'],
            'lines' => [[
                'vendor_bill_line_id' => $bill['lines'][0]['id'],
                'description'  => (string) $bill['lines'][0]['description'],
                'quantity'     => 1,
                'unit_price'   => 50.0,
                'discount_amount' => 0,
                'tax_amount'   => 0,
                'line_total'   => 50.0,
            ]],
        ], $this->headers)->assertCreated()->json('data');

        AccountMapping::query()->where('mapping_key', AccountMappingKey::PURCHASE_RETURN)
            ->update(['account_id' => null]);

        $this->patchJson('/api/purchase/returns/'.$return['id'].'/post', [], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'MAPPING_REQUIRED');
    }

    // -------------------------------------------------------------------------
    // Test 4 — StockAdjustment (increase) without adjustment_gain
    // -------------------------------------------------------------------------

    public function test_stock_adjustment_increase_without_adjustment_gain_mapping_returns_422(): void
    {
        $adj = $this->postJson('/api/inventory/stock-adjustments', [
            'adjustment_date' => '2026-05-01',
            'warehouse_id'    => $this->warehouse->id,
            'lines' => [[
                'product_id'      => $this->stockProduct->id,
                'unit_id'         => $this->unit->id,
                'adjustment_type' => 'increase',
                'quantity'        => 5,
                'unit_cost'       => 10,
                'warehouse_id'    => $this->warehouse->id,
            ]],
        ], $this->headers)->assertCreated()->json('data');

        AccountMapping::query()->where('mapping_key', AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN)
            ->update(['account_id' => null]);

        $this->patchJson('/api/inventory/stock-adjustments/'.$adj['id'].'/post', [], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'MAPPING_REQUIRED');
    }

    // -------------------------------------------------------------------------
    // Test 5 — StockAdjustment (decrease) without adjustment_loss
    // -------------------------------------------------------------------------

    public function test_stock_adjustment_decrease_without_adjustment_loss_mapping_returns_422(): void
    {
        // Seed stock balance so decrease is valid
        \App\Models\Tenant\StockBalance::factory()->create([
            'product_id'       => $this->stockProduct->id,
            'warehouse_id'     => $this->warehouse->id,
            'quantity_on_hand' => 100,
            'total_value'      => 1000,
            'average_cost'     => 10,
        ]);

        $adj = $this->postJson('/api/inventory/stock-adjustments', [
            'adjustment_date' => '2026-05-01',
            'warehouse_id'    => $this->warehouse->id,
            'lines' => [[
                'product_id'      => $this->stockProduct->id,
                'unit_id'         => $this->unit->id,
                'adjustment_type' => 'decrease',
                'quantity'        => 3,
                'warehouse_id'    => $this->warehouse->id,
            ]],
        ], $this->headers)->assertCreated()->json('data');

        AccountMapping::query()->where('mapping_key', AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS)
            ->update(['account_id' => null]);

        $this->patchJson('/api/inventory/stock-adjustments/'.$adj['id'].'/post', [], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'MAPPING_REQUIRED');
    }

    // -------------------------------------------------------------------------
    // Test 6 — SalesInvoice taxable without sales.tax_output
    // -------------------------------------------------------------------------

    public function test_sales_invoice_with_tax_without_tax_output_mapping_returns_422(): void
    {
        $invoice = $this->postJson('/api/sales/invoices', [
            'customer_id'  => $this->customer->id,
            'invoice_date' => '2026-05-01',
            'due_date'     => '2026-05-31',
            'is_taxable'   => false,
            'tax_included' => false,
            'lines' => [[
                'description' => 'Taxed Item',
                'quantity'    => 1,
                'unit_price'  => 100.0,
                'tax_rate'    => 11.0,
            ]],
        ], $this->headers)->assertCreated()->json('data');

        AccountMapping::query()->where('mapping_key', AccountMappingKey::SALES_TAX_OUTPUT)
            ->update(['account_id' => null]);

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'MAPPING_REQUIRED');
    }

    // -------------------------------------------------------------------------
    // Test 7 — VendorBill taxable without purchase.tax_input
    // -------------------------------------------------------------------------

    public function test_vendor_bill_with_tax_without_tax_input_mapping_returns_422(): void
    {
        $bill = $this->postJson('/api/purchase/bills', [
            'vendor_id'  => $this->vendor->id,
            'bill_date'  => '2026-05-01',
            'due_date'   => '2026-05-31',
            'is_taxable' => false,
            'lines' => [[
                'description' => 'Taxed Purchase',
                'quantity'    => 1,
                'unit_price'  => 200.0,
                'tax_rate'    => 10.0,
            ]],
        ], $this->headers)->assertCreated()->json('data');

        AccountMapping::query()->where('mapping_key', AccountMappingKey::PURCHASE_TAX_INPUT)
            ->update(['account_id' => null]);

        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'MAPPING_REQUIRED');
    }

    // -------------------------------------------------------------------------
    // Test 8a — Happy path: SalesReturn posts successfully with mapping present
    // -------------------------------------------------------------------------

    public function test_sales_return_posts_successfully_when_all_mappings_present(): void
    {
        $invoice = $this->postAndPostInvoice(500.0, false);
        $return  = $this->postJson('/api/sales/returns', [
            'customer_id'      => $this->customer->id,
            'return_date'      => '2026-05-02',
            'sales_invoice_id' => $invoice['id'],
            'lines' => [[
                'sales_invoice_line_id' => $invoice['lines'][0]['id'],
                'description'  => (string) $invoice['lines'][0]['description'],
                'quantity'     => 1,
                'unit_price'   => 50.0,
                'discount_amount' => 0,
                'tax_amount'   => 0,
                'line_total'   => 50.0,
            ]],
        ], $this->headers)->assertCreated()->json('data');

        $this->patchJson('/api/sales/returns/'.$return['id'].'/post', [], $this->headers)
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // Test 8b — Happy path: StockAdjustment increase posts with all mappings
    // -------------------------------------------------------------------------

    public function test_stock_adjustment_increase_posts_successfully_when_mapping_present(): void
    {
        $adj = $this->postJson('/api/inventory/stock-adjustments', [
            'adjustment_date' => '2026-05-01',
            'warehouse_id'    => $this->warehouse->id,
            'lines' => [[
                'product_id'      => $this->stockProduct->id,
                'unit_id'         => $this->unit->id,
                'adjustment_type' => 'increase',
                'quantity'        => 2,
                'unit_cost'       => 10,
                'warehouse_id'    => $this->warehouse->id,
            ]],
        ], $this->headers)->assertCreated()->json('data');

        $this->patchJson('/api/inventory/stock-adjustments/'.$adj['id'].'/post', [], $this->headers)
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create and post a SalesInvoice, return its data array (with lines).
     */
    private function postAndPostInvoice(float $amount, bool $taxable): array
    {
        $invoice = $this->postJson('/api/sales/invoices', [
            'customer_id'  => $this->customer->id,
            'invoice_date' => '2026-05-01',
            'due_date'     => '2026-05-31',
            'is_taxable'   => false,
            'tax_included' => false,
            'lines' => [[
                'description' => 'Service Item',
                'quantity'    => 10,
                'unit_price'  => $amount / 10,
            ]],
        ], $this->headers)->assertCreated()->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $this->headers)
            ->assertSuccessful();

        return $this->getJson('/api/sales/invoices/'.$invoice['id'], $this->headers)
            ->assertSuccessful()->json('data');
    }

    /**
     * Create and post a VendorBill, return its data array (with lines).
     */
    private function postAndPostBill(float $amount, bool $taxable): array
    {
        $bill = $this->postJson('/api/purchase/bills', [
            'vendor_id'  => $this->vendor->id,
            'bill_date'  => '2026-05-01',
            'due_date'   => '2026-05-31',
            'is_taxable' => false,
            'lines' => [[
                'description' => 'Purchase Item',
                'quantity'    => 10,
                'unit_price'  => $amount / 10,
            ]],
        ], $this->headers)->assertCreated()->json('data');

        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $this->headers)
            ->assertSuccessful();

        return $this->getJson('/api/purchase/bills/'.$bill['id'], $this->headers)
            ->assertSuccessful()->json('data');
    }

    /**
     * Seed all account mappings needed for Sales, Purchase, and Inventory flows.
     * Uses pre-seeded accounts (1000=Cash, 4000=Revenue) from JournalTestCase.
     *
     * @return array<string,int>
     */
    private function seedAllMappings(): array
    {
        $cash    = ChartOfAccount::query()->where('account_code', '1000')->firstOrFail();
        $revenue = ChartOfAccount::query()->where('account_code', '4000')->firstOrFail();

        $ar      = ChartOfAccount::factory()->asset()->create(['account_code' => '1200', 'account_name' => 'AR']);
        $ap      = ChartOfAccount::factory()->liability()->create(['account_code' => '2100', 'account_name' => 'AP']);
        $interim = ChartOfAccount::factory()->liability()->create(['account_code' => '2110', 'account_name' => 'GRNI']);
        $inv     = ChartOfAccount::factory()->asset()->create(['account_code' => '1300', 'account_name' => 'Inventory']);
        $cogs    = ChartOfAccount::factory()->expense()->create(['account_code' => '5000', 'account_name' => 'COGS']);
        $adjGain = ChartOfAccount::factory()->create(['account_code' => '4100', 'account_name' => 'Adj Gain', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true]);
        $adjLoss = ChartOfAccount::factory()->expense()->create(['account_code' => '5100', 'account_name' => 'Adj Loss']);
        $taxOut  = ChartOfAccount::factory()->liability()->create(['account_code' => '2200', 'account_name' => 'Tax Output']);
        $taxIn   = ChartOfAccount::factory()->asset()->create(['account_code' => '1400', 'account_name' => 'Tax Input']);
        $salRet  = ChartOfAccount::factory()->expense()->create(['account_code' => '4900', 'account_name' => 'Sales Return']);
        $purRet  = ChartOfAccount::factory()->create(['account_code' => '5900', 'account_name' => 'Purchase Return', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true]);
        $purExp  = ChartOfAccount::factory()->expense()->create(['account_code' => '5200', 'account_name' => 'Purchase Expense']);
        $deposit = ChartOfAccount::factory()->liability()->create(['account_code' => '2300', 'account_name' => 'Customer Deposit']);

        $mappings = [
            AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE  => $ar->id,
            AccountMappingKey::SALES_REVENUE               => $revenue->id,
            AccountMappingKey::SALES_RETURN                => $salRet->id,
            AccountMappingKey::SALES_TAX_OUTPUT            => $taxOut->id,
            AccountMappingKey::SALES_CUSTOMER_DEPOSIT      => $deposit->id,
            AccountMappingKey::PURCHASE_ACCOUNTS_PAYABLE   => $ap->id,
            AccountMappingKey::PURCHASE_INVENTORY_INTERIM  => $interim->id,
            AccountMappingKey::PURCHASE_TAX_INPUT          => $taxIn->id,
            AccountMappingKey::PURCHASE_RETURN             => $purRet->id,
            AccountMappingKey::PURCHASE_EXPENSE            => $purExp->id,
            AccountMappingKey::INVENTORY_ASSET             => $inv->id,
            AccountMappingKey::INVENTORY_COGS              => $cogs->id,
            AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN   => $adjGain->id,
            AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS   => $adjLoss->id,
        ];

        foreach ($mappings as $key => $accountId) {
            AccountMapping::factory()->create([
                'mapping_key' => $key,
                'module'      => explode('.', $key)[0],
                'account_id'  => $accountId,
                'is_required' => true,
                'is_active'   => true,
            ]);
        }

        return [
            'cash'    => (int) $cash->id,
            'revenue' => (int) $revenue->id,
            'ar'      => (int) $ar->id,
            'ap'      => (int) $ap->id,
        ];
    }
}
