<?php

declare(strict_types=1);

namespace Tests\Feature\Purchase;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\TenantTestCase;

class DirectVendorBillStockTest extends TenantTestCase
{
    protected Unit $unit;

    protected Warehouse $warehouse;

    /**
     * @var array<string, int>
     */
    protected array $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounts = $this->seedPurchaseAndInventoryMappings();
        $this->unit = Unit::query()->create([
            'code' => 'PCS',
            'name' => 'Pieces',
            'precision' => 0,
            'is_active' => true,
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'code' => 'WH1',
            'name' => 'Main',
            'is_default' => true,
        ]);
    }

    protected function tenantRole(): string
    {
        return 'owner';
    }

    protected function accountingSettingOverrides(): array
    {
        return [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
            'approval_enabled' => false,
        ];
    }

    public function test_direct_vendor_bill_for_stock_item_creates_purchase_in_movement_and_debits_inventory_exactly_once(): void
    {
        $vendor = $this->createVendor();
        $product = $this->createProduct('H6-STOCK-1', true);

        $bill = $this->createVendorBill($vendor, [
            $this->billLine($product, 10, 100, $this->warehouse->id),
        ], false);

        $this->postBill($bill['id']);

        $movement = StockMovement::query()
            ->where('source_type', 'vendor_bill')
            ->where('source_id', $bill['id'])
            ->firstOrFail();

        $this->assertSame('purchase_in', (string) $movement->movement_type);
        $this->assertBalanceQuantity($product, 10.0);
        $this->assertJournalDebit($bill['id'], $this->accounts['inventory'], 1000.0);
        $this->assertJournalCredit($bill['id'], $this->accounts['ap'], 1000.0);
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'stock_movement')
            ->where('source_id', $movement->id)
            ->count());
        $this->assertBillJournalBalances($bill['id']);
    }

    public function test_direct_vendor_bill_for_non_stock_item_creates_no_movement_and_debits_expense_account(): void
    {
        $vendor = $this->createVendor();
        $product = $this->createProduct('H6-NONSTOCK-1', false);

        $bill = $this->createVendorBill($vendor, [
            $this->billLine($product, 5, 200, null),
        ], false);

        $this->postBill($bill['id']);

        $this->assertSame(0, StockMovement::query()
            ->where('source_type', 'vendor_bill')
            ->where('source_id', $bill['id'])
            ->count());
        $this->assertJournalDebit($bill['id'], $this->accounts['expense'], 1000.0);
        $this->assertJournalCredit($bill['id'], $this->accounts['ap'], 1000.0);
        $this->assertBillJournalBalances($bill['id']);
    }

    public function test_vendor_bill_from_goods_receipt_clears_grni_and_does_not_create_duplicate_stock_movement(): void
    {
        $vendor = $this->createVendor();
        $product = $this->createProduct('H6-GR-STOCK', true);

        $order = $this->postJson('/api/purchase/orders', [
            'vendor_id' => $vendor->id,
            'order_date' => '2026-05-20',
            'is_taxable' => false,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'description' => 'GR stock item',
                    'quantity' => 10,
                    'unit_id' => $this->unit->id,
                    'unit_price' => 100,
                    'warehouse_id' => $this->warehouse->id,
                ],
            ],
        ], $this->headers)->assertCreated()->json('data');

        $this->patchJson('/api/purchase/orders/'.$order['id'].'/confirm', [], $this->headers)
            ->assertSuccessful();

        $receipt = $this->postJson('/api/purchase/goods-receipts/from-purchase-order/'.$order['id'], [], $this->headers)
            ->assertCreated()
            ->json('data');

        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'].'/receive', [], $this->headers)
            ->assertSuccessful();

        $grMovement = StockMovement::query()
            ->where('source_type', 'goods_receipt')
            ->where('source_id', $receipt['id'])
            ->firstOrFail();
        $this->assertBalanceQuantity($product, 10.0);

        $bill = $this->postJson('/api/purchase/bills/from-goods-receipt/'.$receipt['id'], [], $this->headers)
            ->assertCreated()
            ->json('data');

        $this->postBill($bill['id']);

        $this->assertSame(0, StockMovement::query()
            ->where('source_type', 'vendor_bill')
            ->where('source_id', $bill['id'])
            ->count());
        $this->assertSame((int) $grMovement->id, (int) StockMovement::query()
            ->where('source_type', 'goods_receipt')
            ->where('source_id', $receipt['id'])
            ->firstOrFail()
            ->id);
        $this->assertJournalDebit($bill['id'], $this->accounts['interim'], 1000.0);
        $this->assertJournalCredit($bill['id'], $this->accounts['ap'], 1000.0);
        $this->assertBalanceQuantity($product, 10.0);
        $this->assertBillJournalBalances($bill['id']);
    }

    public function test_direct_vendor_bill_with_tax_creates_correct_journal_including_tax_input(): void
    {
        $vendor = $this->createVendor();
        $product = $this->createProduct('H6-STOCK-TAX', true);

        $bill = $this->createVendorBill($vendor, [
            $this->billLine($product, 10, 100, $this->warehouse->id, 11),
        ], true);

        $this->postBill($bill['id']);

        $this->assertJournalDebit($bill['id'], $this->accounts['inventory'], 1000.0);
        $this->assertJournalDebit($bill['id'], $this->accounts['tax'], 110.0);
        $this->assertJournalCredit($bill['id'], $this->accounts['ap'], 1110.0);
        $this->assertBillJournalBalances($bill['id']);
    }

    public function test_direct_vendor_bill_with_mixed_stock_and_non_stock_lines_splits_debit_to_correct_accounts(): void
    {
        $vendor = $this->createVendor();
        $stockProduct = $this->createProduct('H6-MIX-STOCK', true);
        $nonStockProduct = $this->createProduct('H6-MIX-NONSTOCK', false);

        $bill = $this->createVendorBill($vendor, [
            $this->billLine($stockProduct, 5, 200, $this->warehouse->id),
            $this->billLine($nonStockProduct, 2, 300, null),
        ], false);

        $this->postBill($bill['id']);

        $movement = StockMovement::query()
            ->where('source_type', 'vendor_bill')
            ->where('source_id', $bill['id'])
            ->firstOrFail();
        $this->assertSame(1, $movement->lines()->count());
        $this->assertJournalDebit($bill['id'], $this->accounts['inventory'], 1000.0);
        $this->assertJournalDebit($bill['id'], $this->accounts['expense'], 600.0);
        $this->assertJournalCredit($bill['id'], $this->accounts['ap'], 1600.0);
        $this->assertBillJournalBalances($bill['id']);
    }

    private function createVendor(): Contact
    {
        return Contact::factory()->vendor()->create([
            'name' => 'H6 Vendor '.uniqid(),
        ]);
    }

    private function createProduct(string $code, bool $stockItem): Product
    {
        return Product::factory()->create([
            'product_code' => $code,
            'product_name' => $code,
            'product_type' => 'goods',
            'unit_id' => $this->unit->id,
            'is_stock_item' => $stockItem,
            'is_active' => true,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function createVendorBill(Contact $vendor, array $lines, bool $taxable): array
    {
        return $this->postJson('/api/purchase/bills', [
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-05-20',
            'due_date' => '2026-05-30',
            'is_taxable' => $taxable,
            'tax_included' => false,
            'lines' => $lines,
        ], $this->headers)->assertCreated()->json('data');
    }

    /**
     * @return array<string, mixed>
     */
    private function billLine(Product $product, float $quantity, float $unitPrice, ?int $warehouseId, ?float $taxRate = null): array
    {
        return [
            'product_id' => $product->id,
            'description' => $product->product_name,
            'quantity' => $quantity,
            'unit_id' => $this->unit->id,
            'unit_price' => $unitPrice,
            'warehouse_id' => $warehouseId,
            'tax_rate' => $taxRate,
        ];
    }

    private function postBill(int $billId): void
    {
        $this->patchJson('/api/purchase/bills/'.$billId.'/post', [], $this->headers)
            ->assertSuccessful();
    }

    private function assertBalanceQuantity(Product $product, float $expected): void
    {
        $balance = StockBalance::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();

        $this->assertSame($expected, (float) $balance->quantity_on_hand);
    }

    private function assertJournalDebit(int $billId, int $accountId, float $expected): void
    {
        $this->assertSame($expected, $this->journalAmount($billId, $accountId, 'debit'));
    }

    private function assertJournalCredit(int $billId, int $accountId, float $expected): void
    {
        $this->assertSame($expected, $this->journalAmount($billId, $accountId, 'credit'));
    }

    private function journalAmount(int $billId, int $accountId, string $column): float
    {
        $journal = JournalEntry::query()
            ->where('source_type', 'vendor_bill')
            ->where('source_id', $billId)
            ->firstOrFail();

        return (float) $journal->lines()
            ->where('account_id', $accountId)
            ->sum($column);
    }

    private function assertBillJournalBalances(int $billId): void
    {
        $journal = JournalEntry::query()
            ->where('source_type', 'vendor_bill')
            ->where('source_id', $billId)
            ->with('lines')
            ->firstOrFail();

        $this->assertSame(
            round((float) $journal->lines->sum('debit'), 2),
            round((float) $journal->lines->sum('credit'), 2),
        );
    }

    /**
     * @return array<string, int>
     */
    private function seedPurchaseAndInventoryMappings(): array
    {
        $accounts = [
            'ap' => ChartOfAccount::factory()->liability()->create(['account_code' => '2100', 'account_name' => 'Accounts Payable'])->id,
            'inventory' => ChartOfAccount::factory()->asset()->create(['account_code' => '1400', 'account_name' => 'Inventory'])->id,
            'expense' => ChartOfAccount::factory()->expense()->create(['account_code' => '6100', 'account_name' => 'Purchase Expense'])->id,
            'interim' => ChartOfAccount::factory()->liability()->create(['account_code' => '2150', 'account_name' => 'Inventory Interim'])->id,
            'tax' => ChartOfAccount::factory()->asset()->create(['account_code' => '1140', 'account_name' => 'Input Tax'])->id,
            'cogs' => ChartOfAccount::factory()->expense()->create(['account_code' => '5100', 'account_name' => 'COGS'])->id,
            'gain' => ChartOfAccount::factory()->revenue()->create(['account_code' => '4100', 'account_name' => 'Adjustment Gain'])->id,
            'loss' => ChartOfAccount::factory()->expense()->create(['account_code' => '5200', 'account_name' => 'Adjustment Loss'])->id,
            'equity' => ChartOfAccount::factory()->equity()->create(['account_code' => '3000', 'account_name' => 'Equity'])->id,
        ];

        $mappings = [
            AccountMappingKey::PURCHASE_ACCOUNTS_PAYABLE => ['purchase', $accounts['ap']],
            AccountMappingKey::INVENTORY_ASSET => ['inventory', $accounts['inventory']],
            AccountMappingKey::PURCHASE_EXPENSE => ['purchase', $accounts['expense']],
            AccountMappingKey::PURCHASE_INVENTORY_INTERIM => ['purchase', $accounts['interim']],
            AccountMappingKey::PURCHASE_TAX_INPUT => ['purchase', $accounts['tax']],
            AccountMappingKey::INVENTORY_COGS => ['inventory', $accounts['cogs']],
            AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN => ['inventory', $accounts['gain']],
            AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS => ['inventory', $accounts['loss']],
            AccountMappingKey::OPENING_BALANCE_EQUITY => ['opening_balance', $accounts['equity']],
        ];

        foreach ($mappings as $key => [$module, $accountId]) {
            AccountMapping::factory()->create([
                'mapping_key' => $key,
                'module' => $module,
                'account_id' => $accountId,
                'is_required' => true,
                'is_active' => true,
            ]);
        }

        return array_map('intval', $accounts);
    }
}
