<?php

namespace Tests\Feature\Purchase;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Models\User;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class PurchaseTestCase extends TestCase
{
    use RefreshDatabase;

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

        Sanctum::actingAs($user);

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
        return (int) \App\Models\Tenant\Contact::query()->create(array_merge([
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
                    'description' => 'Office chair',
                    'quantity' => 2,
                    'unit_price' => 100,
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
                    'description' => 'Office chair',
                    'quantity' => 2,
                ],
            ],
        ], $overrides);
    }

    protected function createAccount(string $type, string $code, bool $cashBank = false): int
    {
        return (int) \App\Models\Tenant\ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $code,
            'account_type' => $type,
            'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit',
            'is_cash_bank' => $cashBank,
            'is_active' => true,
        ])->id;
    }

    protected function seedPurchaseMappings(): array
    {
        $ap = $this->createAccount('liability', 'AP-'.uniqid());
        $expense = $this->createAccount('expense', 'EXP-'.uniqid());
        $tax = $this->createAccount('asset', 'TAX-'.uniqid());
        $deposit = $this->createAccount('asset', 'VD-'.uniqid());
        $return = $this->createAccount('expense', 'PRET-'.uniqid());
        $cash = $this->createAccount('asset', 'CASH-'.uniqid(), true);

        foreach ([
            'purchase.accounts_payable' => $ap,
            'purchase.expense' => $expense,
            'purchase.tax_input' => $tax,
            'purchase.vendor_deposit' => $deposit,
            'purchase.return' => $return,
            'purchase.default_cash_bank' => $cash,
        ] as $key => $id) {
            \App\Models\Tenant\AccountMapping::query()->updateOrCreate(
                ['mapping_key' => $key],
                ['module' => 'purchase', 'account_id' => $id, 'is_required' => true, 'is_active' => true]
            );
        }

        return compact('ap', 'expense', 'tax', 'deposit', 'return', 'cash');
    }

    protected function vendorBillPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'vendor_id' => $this->createVendor(),
            'bill_date' => '2026-05-20',
            'due_date' => '2026-05-30',
            'is_taxable' => true,
            'lines' => [
                [
                    'description' => 'Purchase service',
                    'quantity' => 2,
                    'unit_price' => 100,
                    'tax_rate' => 11,
                ],
            ],
        ], $overrides);
    }
}
