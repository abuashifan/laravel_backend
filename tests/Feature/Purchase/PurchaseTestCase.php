<?php

namespace Tests\Feature\Purchase;

use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Models\Warehouse;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyAccountingSetting;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class PurchaseTestCase extends TestCase
{
    use RefreshDatabase;

    // Fixture stok default (dibuat di setUpTenant) — vendor bill hanya boleh berisi baris stok
    // atau aset tetap, jadi payload default memakai baris stok yang valid.
    protected ?int $defaultUnitId = null;

    protected ?int $defaultWarehouseId = null;

    protected ?int $defaultStockProductId = null;

    protected function setUpTenant(string $role = 'owner'): array
    {
        $user = User::factory()->create(['status' => 'active']);

        $company = Company::query()->create([
            'name' => 'Company Purchase',
            'slug' => 'company-purchase-'.$user->id,
            'code' => 'CMP-PUR-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        CompanyAccountingSetting::query()->create([
            'company_id' => $company->id,
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
            'approval_enabled' => false,
        ]);

        $tenantPath = database_path('tenants/test_purchase_'.$company->id.'_'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath));
        File::put($tenantPath, '');

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => basename($tenantPath),
            'database_path' => $tenantPath,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        app(TenantConnectionManager::class)->connect($tenantPath);

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        // Kode unik agar tidak bentrok dengan unit/gudang yang dibuat test tertentu.
        // is_default=false agar tidak melanggar batasan "hanya satu gudang default".
        $this->defaultUnitId = (int) Unit::query()->create([
            'code' => 'DFU', 'name' => 'Default Unit', 'precision' => 0, 'is_active' => true,
        ])->id;
        $this->defaultWarehouseId = (int) Warehouse::factory()->create([
            'code' => 'WH-DEF', 'name' => 'Default WH', 'is_default' => false,
        ])->id;
        $this->defaultStockProductId = (int) Product::factory()->create([
            'product_code' => 'STK-DEF',
            'product_name' => 'Default Stock Item',
            'product_type' => 'goods',
            'unit_id' => $this->defaultUnitId,
            'is_stock_item' => true,
            'is_active' => true,
        ])->id;

        Sanctum::actingAs($user, ['*']);

        return [
            'user' => $user,
            'company' => $company,
            'headers' => ['X-Company-ID' => (string) $company->id],
            'tenant_path' => $tenantPath,
        ];
    }

    protected function purchaseRequestPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'request_date' => '2026-05-20',
            'needed_date' => '2026-05-25',
            'notes' => 'Need office supplies',
            'lines' => [
                [
                    'description' => 'Printer paper',
                    'quantity' => 2,
                    'estimated_unit_price' => 75000,
                ],
            ],
        ], $overrides);
    }

    protected function createVendor(array $attributes = []): int
    {
        return (int) Contact::query()->create(array_merge([
            'name' => 'Vendor A',
            'contact_type' => 'supplier',
            'is_supplier' => true,
            'is_active' => true,
        ], $attributes))->id;
    }

    protected function purchaseOrderPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'vendor_id' => $this->createVendor(),
            'order_date' => '2026-05-20',
            'has_down_payment' => false,
            'is_taxable' => true,
            'tax_included' => false,
            'lines' => [
                [
                    'product_id' => $this->defaultStockProductId,
                    'description' => 'Office chair',
                    'quantity' => 2,
                    'unit_id' => $this->defaultUnitId,
                    'unit_price' => 100,
                    'warehouse_id' => $this->defaultWarehouseId,
                    'tax_rate' => 11,
                ],
            ],
        ], $overrides);
    }

    protected function goodsReceiptPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'vendor_id' => $this->createVendor(),
            'receipt_date' => '2026-05-20',
            'lines' => [
                [
                    'product_id' => $this->defaultStockProductId,
                    'description' => 'Office chair',
                    'quantity' => 2,
                    'unit_id' => $this->defaultUnitId,
                    'warehouse_id' => $this->defaultWarehouseId,
                ],
            ],
        ], $overrides);
    }

    protected function createAccount(string $type, string $code, bool $cashBank = false): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $code,
            'account_type' => $type,
            'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit',
            'is_cash_bank' => $cashBank,
            'is_active' => true,
        ])->id;
    }

    protected function seedPurchaseMappings(bool $payable = true, bool $legacyPayable = false, bool $expense = true, bool $interim = false): array
    {
        $ap = $this->createAccount('liability', 'AP-'.uniqid());
        $expenseAccount = $expense ? $this->createAccount('expense', 'EXP-'.uniqid()) : null;
        $tax = $this->createAccount('asset', 'TAX-'.uniqid());
        $deposit = $this->createAccount('asset', 'VD-'.uniqid());
        $return = $this->createAccount('expense', 'PRET-'.uniqid());
        $cash = $this->createAccount('asset', 'CASH-'.uniqid(), true);
        $inventory = $this->createAccount('asset', 'INV-'.uniqid());
        $interimAccount = $interim ? $this->createAccount('liability', 'GRNI-'.uniqid()) : null;

        foreach ([
            'purchase.tax_input' => $tax,
            'purchase.vendor_deposit' => $deposit,
            'purchase.return' => $return,
            'purchase.default_cash_bank' => $cash,
            'inventory.asset' => $inventory,
        ] + ($payable ? ['purchase.accounts_payable' => $ap] : [])
            + ($legacyPayable ? ['purchase.payable' => $ap] : [])
            + ($expenseAccount !== null ? ['purchase.expense' => $expenseAccount] : [])
            + ($interimAccount !== null ? ['purchase.inventory_interim' => $interimAccount] : []) as $key => $id) {
            AccountMapping::query()->updateOrCreate(
                ['mapping_key' => $key],
                ['module' => 'purchase', 'account_id' => $id, 'is_required' => true, 'is_active' => true]
            );
        }

        return ['ap' => $ap, 'expense' => $expenseAccount, 'tax' => $tax, 'deposit' => $deposit, 'return' => $return, 'cash' => $cash, 'inventory' => $inventory, 'interim' => $interimAccount];
    }

    protected function vendorBillPayload(array $overrides = []): array
    {
        $payload = array_replace_recursive([
            'vendor_id' => $this->createVendor(),
            'bill_date' => '2026-05-20',
            'due_date' => '2026-05-30',
            'is_taxable' => true,
            // Baris stok valid (produk stok + gudang) — memenuhi aturan "bill hanya stok/aset tetap".
            'lines' => [
                [
                    'product_id' => $this->defaultStockProductId,
                    'description' => 'Default stock item',
                    'quantity' => 2,
                    'unit_id' => $this->defaultUnitId,
                    'unit_price' => 100,
                    'warehouse_id' => $this->defaultWarehouseId,
                    'tax_rate' => 11,
                ],
            ],
        ], $overrides);

        // Override 'lines' harus mengganti penuh (bukan deep-merge array_replace_recursive),
        // agar field baris default tidak bocor ke baris yang disediakan test.
        if (array_key_exists('lines', $overrides)) {
            $payload['lines'] = $overrides['lines'];
        }

        return $payload;
    }
}
