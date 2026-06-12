<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Unit;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\Warehouse;
use App\Services\Accounting\FiscalYearService;
use App\Services\Purchase\VendorBillService;
use App\Support\AccountMapping\AccountMappingKey;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TenantTestCase;

class DocumentLifecycleMatrixTest extends TenantTestCase
{
    private Contact $customer;
    private Contact $vendor;
    private Unit $unit;
    private Warehouse $warehouse;
    private Product $product;

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
            'allow_future_transactions' => true,
            'max_future_days' => null,
            'allow_backdated_transactions' => true,
            'max_backdate_days' => null,
            'block_outside_current_fiscal_year' => false,
            'date_warning_enabled' => false,
            'require_void_reason' => true,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Contact::query()->create([
            'name' => 'Lifecycle Customer',
            'contact_type' => 'customer',
            'is_customer' => true,
            'is_active' => true,
        ]);
        $this->vendor = Contact::query()->create([
            'name' => 'Lifecycle Vendor',
            'contact_type' => 'supplier',
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $this->unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $this->warehouse = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $this->product = Product::query()->create([
            'product_code' => 'LC-SKU',
            'product_name' => 'Lifecycle Stock',
            'product_type' => 'goods',
            'unit_id' => $this->unit->id,
            'is_stock_item' => true,
            'is_active' => true,
        ]);

        $this->seedMappings();
    }

    #[DataProvider('documentProvider')]
    public function test_edit_guard(string $document): void
    {
        $draft = $this->createDocument($document);

        $this->updateDocument($document, $draft['id'])
            ->assertStatus(200);

        $posted = $this->createDocument($document);
        $this->postDocument($document, $posted['id'])->assertStatus(200);

        $this->updateDocument($document, $posted['id'])
            ->assertStatus(422);
    }

    #[DataProvider('documentProvider')]
    public function test_post_guard(string $document): void
    {
        $draft = $this->createDocument($document);

        $this->postDocument($document, $draft['id'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', $this->postedStatus($document));

        $this->postDocument($document, $draft['id'])
            ->assertStatus(422);
    }

    #[DataProvider('documentProvider')]
    public function test_void_guard(string $document): void
    {
        $posted = $this->createDocument($document);
        $this->postDocument($document, $posted['id'])->assertStatus(200);

        if ($document === 'vendor_bill') {
            $this->assertVendorBillVoidWithoutReasonGuard((int) $posted['id']);
        } else {
            $this->assertGuardFailure($this->voidDocument($document, $posted['id'], null));
        }

        $this->voidDocument($document, $posted['id'], 'Lifecycle correction')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');

        if ($document === 'vendor_bill') {
            $this->assertVendorBillDoubleVoidGuard((int) $posted['id']);
        } else {
            $this->assertGuardFailure($this->voidDocument($document, $posted['id'], 'Again'));
        }

        $draft = $this->createDocument($document);
        $this->voidDocument($document, $draft['id'], 'Draft void behavior')
            ->assertStatus($this->draftVoidStatus($document));
    }

    #[DataProvider('documentProvider')]
    public function test_period_lock_guard(string $document): void
    {
        $this->setPeriodStatus(2026, 5, 'closed');
        $draft = $this->createDocument($document);

        $this->postDocument($document, $draft['id'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_PERIOD_LOCKED');

        $this->setPeriodStatus(2026, 5, 'open');
        $posted = $this->createDocument($document);
        $this->postDocument($document, $posted['id'])->assertStatus(200);

        $this->setPeriodStatus(2026, 5, 'closed');
        $this->voidDocument($document, $posted['id'], 'Locked period void')
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_PERIOD_LOCKED');
    }

    public static function documentProvider(): array
    {
        return [
            'sales invoice' => ['sales_invoice'],
            'vendor bill' => ['vendor_bill'],
            'stock adjustment' => ['stock_adjustment'],
            'goods receipt' => ['goods_receipt'],
        ];
    }

    private function createDocument(string $document): array
    {
        return match ($document) {
            'sales_invoice' => $this->postJson('/api/sales/invoices', $this->salesInvoicePayload(), $this->headers)->assertStatus(201)->json('data'),
            'vendor_bill' => $this->postJson('/api/purchase/bills', $this->vendorBillPayload(), $this->headers)->assertStatus(201)->json('data'),
            'stock_adjustment' => $this->postJson('/api/inventory/stock-adjustments', $this->stockAdjustmentPayload(), $this->headers)->assertStatus(201)->json('data'),
            'goods_receipt' => $this->postJson('/api/purchase/goods-receipts', $this->goodsReceiptPayload(), $this->headers)->assertStatus(201)->json('data'),
            default => throw new \InvalidArgumentException($document),
        };
    }

    private function updateDocument(string $document, int $id): \Illuminate\Testing\TestResponse
    {
        return match ($document) {
            'sales_invoice' => $this->patchJson('/api/sales/invoices/'.$id, $this->salesInvoicePayload(['notes' => 'Updated']), $this->headers),
            'vendor_bill' => $this->patchJson('/api/purchase/bills/'.$id, $this->vendorBillPayload(['notes' => 'Updated']), $this->headers),
            'stock_adjustment' => $this->patchJson('/api/inventory/stock-adjustments/'.$id, ['reason' => 'Updated'], $this->headers),
            'goods_receipt' => $this->patchJson('/api/purchase/goods-receipts/'.$id, $this->goodsReceiptPayload(['notes' => 'Updated']), $this->headers),
            default => throw new \InvalidArgumentException($document),
        };
    }

    private function postDocument(string $document, int $id): \Illuminate\Testing\TestResponse
    {
        return match ($document) {
            'sales_invoice' => $this->patchJson('/api/sales/invoices/'.$id.'/post', [], $this->headers),
            'vendor_bill' => $this->patchJson('/api/purchase/bills/'.$id.'/post', [], $this->headers),
            'stock_adjustment' => $this->patchJson('/api/inventory/stock-adjustments/'.$id.'/post', [], $this->headers),
            'goods_receipt' => $this->patchJson('/api/purchase/goods-receipts/'.$id.'/receive', [], $this->headers),
            default => throw new \InvalidArgumentException($document),
        };
    }

    private function voidDocument(string $document, int $id, ?string $reason): \Illuminate\Testing\TestResponse
    {
        $payload = $reason === null ? [] : ['reason' => $reason];

        return match ($document) {
            'sales_invoice' => $this->patchJson('/api/sales/invoices/'.$id.'/void', $payload, $this->headers),
            'vendor_bill' => $this->patchJson('/api/purchase/bills/'.$id.'/void', $payload, $this->headers),
            'stock_adjustment' => $this->patchJson('/api/inventory/stock-adjustments/'.$id.'/void', $payload, $this->headers),
            'goods_receipt' => $this->patchJson('/api/purchase/goods-receipts/'.$id.'/void', $payload, $this->headers),
            default => throw new \InvalidArgumentException($document),
        };
    }

    private function postedStatus(string $document): string
    {
        return $document === 'goods_receipt' ? 'received' : 'posted';
    }

    private function draftVoidStatus(string $document): int
    {
        return match ($document) {
            'sales_invoice', 'vendor_bill', 'stock_adjustment' => 200,
            default => 422,
        };
    }

    private function assertGuardFailure(\Illuminate\Testing\TestResponse $response): void
    {
        if ($response->exception instanceof ApiException) {
            $this->assertSame(422, $response->exception->status);
            return;
        }

        $response->assertStatus(422);
    }

    private function assertVendorBillDoubleVoidGuard(int $id): void
    {
        try {
            app(VendorBillService::class)->void(VendorBill::query()->findOrFail($id), 'Again');
            $this->fail('Expected vendor bill double void to be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame('VENDOR_BILL_ALREADY_VOID', $exception->codeName);
        }
    }

    private function assertVendorBillVoidWithoutReasonGuard(int $id): void
    {
        try {
            app(VendorBillService::class)->void(VendorBill::query()->findOrFail($id), null);
            $this->fail('Expected vendor bill void without reason to be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame('VALIDATION_ERROR', $exception->codeName);
        }
    }

    private function salesInvoicePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-05-20',
            'due_date' => '2026-05-30',
            'is_taxable' => false,
            'tax_included' => false,
            'lines' => [
                ['description' => 'Lifecycle service', 'quantity' => 1, 'unit_price' => 100],
            ],
        ], $overrides);
    }

    private function vendorBillPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2026-05-20',
            'due_date' => '2026-05-30',
            'is_taxable' => false,
            'tax_included' => false,
            'lines' => [
                ['description' => 'Lifecycle purchase', 'quantity' => 1, 'unit_price' => 100],
            ],
        ], $overrides);
    }

    private function stockAdjustmentPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'adjustment_date' => '2026-05-20',
            'reason' => 'Lifecycle adjustment',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'warehouse_id' => $this->warehouse->id,
                    'unit_id' => $this->unit->id,
                    'adjustment_type' => 'increase',
                    'quantity' => 1,
                    'unit_cost' => 100,
                ],
            ],
        ], $overrides);
    }

    private function goodsReceiptPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'vendor_id' => $this->vendor->id,
            'receipt_date' => '2026-05-20',
            'lines' => [
                ['description' => 'Lifecycle receipt', 'quantity' => 1],
            ],
        ], $overrides);
    }

    private function setPeriodStatus(int $year, int $month, string $status): void
    {
        app(FiscalYearService::class)->getOrCreateActiveFiscalYear($this->company, $year);

        AccountingPeriod::query()
            ->where('company_id', $this->company->id)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->update([
                'status' => $status,
                'closed_at' => $status === 'closed' ? now() : null,
                'closed_by' => $status === 'closed' ? $this->user->id : null,
            ]);
    }

    private function seedMappings(): void
    {
        $this->mapping(AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE, 'sales', $this->account('1100', 'Accounts Receivable', 'asset', 'debit'));
        $this->mapping(AccountMappingKey::SALES_REVENUE, 'sales', $this->account('4100', 'Sales Revenue', 'revenue', 'credit'));
        $this->mapping(AccountMappingKey::SALES_RETURN, 'sales', $this->account('4200', 'Sales Return', 'revenue', 'credit'));
        $this->mapping(AccountMappingKey::SALES_TAX_OUTPUT, 'sales', $this->account('2100', 'Output Tax', 'liability', 'credit'));
        $this->mapping(AccountMappingKey::SALES_CUSTOMER_DEPOSIT, 'sales', $this->account('2200', 'Customer Deposit', 'liability', 'credit'));

        $this->mapping(AccountMappingKey::PURCHASE_ACCOUNTS_PAYABLE, 'purchase', $this->account('2300', 'Accounts Payable', 'liability', 'credit'));
        $this->mapping(AccountMappingKey::PURCHASE_EXPENSE, 'purchase', $this->account('5100', 'Purchase Expense', 'expense', 'debit'));
        $this->mapping(AccountMappingKey::PURCHASE_INVENTORY_INTERIM, 'purchase', $this->account('2400', 'GRNI', 'liability', 'credit'));
        $this->mapping(AccountMappingKey::PURCHASE_TAX_INPUT, 'purchase', $this->account('1300', 'Input Tax', 'asset', 'debit'));
        $this->mapping(AccountMappingKey::PURCHASE_RETURN, 'purchase', $this->account('5200', 'Purchase Return', 'expense', 'debit'));
        $this->mapping(AccountMappingKey::PURCHASE_VENDOR_DEPOSIT, 'purchase', $this->account('1400', 'Vendor Deposit', 'asset', 'debit'));

        $this->mapping(AccountMappingKey::INVENTORY_ASSET, 'inventory', $this->account('1500', 'Inventory', 'asset', 'debit'));
        $this->mapping(AccountMappingKey::INVENTORY_COGS, 'inventory', $this->account('5300', 'COGS', 'expense', 'debit'));
        $this->mapping(AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN, 'inventory', $this->account('4300', 'Adjustment Gain', 'revenue', 'credit'));
        $this->mapping(AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS, 'inventory', $this->account('5400', 'Adjustment Loss', 'expense', 'debit'));
        $this->mapping(AccountMappingKey::OPENING_BALANCE_EQUITY, 'opening_balance', $this->account('3100', 'Opening Equity', 'equity', 'credit'));
    }

    private function account(string $code, string $name, string $type, string $normalBalance): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normalBalance,
            'is_active' => true,
            'is_system_default' => false,
        ])->id;
    }

    private function mapping(string $key, string $module, int $accountId): void
    {
        AccountMapping::query()->create([
            'mapping_key' => $key,
            'module' => $module,
            'account_id' => $accountId,
            'is_required' => true,
            'is_active' => true,
        ]);
    }
}
