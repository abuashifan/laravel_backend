<?php

namespace Tests\Feature\Sales;

use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Product;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bulk post invoice — Fase 3 rencana impor data.
 */
class BulkPostInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_post_by_ids_returns_success_response(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedCustomer();
        $this->seedAccountMappings();

        $inv1 = $this->createDraftInvoice($ctx, '2026-08-11');
        $inv2 = $this->createDraftInvoice($ctx, '2026-08-12');

        $res = $this->postJson('/api/sales/invoices/bulk-post', [
            'ids' => [$inv1->id, $inv2->id],
        ], $ctx['headers'])->assertOk();

        // Verifikasi struktur respons.
        $this->assertArrayHasKey('posted_count', $res->json('data'));
        $this->assertArrayHasKey('failed_count', $res->json('data'));
        $this->assertArrayHasKey('results', $res->json('data'));
    }

    public function test_bulk_post_with_empty_ids_returns_zero_results(): void
    {
        $ctx = $this->setUpTenant();

        $res = $this->postJson('/api/sales/invoices/bulk-post', [
            'ids' => [],
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_bulk_post_validates_required_input(): void
    {
        $ctx = $this->setUpTenant();

        // Tidak ada ids maupun import_batch_uuid → harus gagal validasi.
        $this->postJson('/api/sales/invoices/bulk-post', [
            'ids' => null,
        ], $ctx['headers'])->assertStatus(422);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedCustomer(): void
    {
        Contact::query()->create([
            'contact_code' => 'CUST', 'name' => 'Test Customer', 'contact_type' => 'customer',
            'is_customer' => true, 'is_active' => true,
        ]);
        Product::query()->create([
            'product_code' => 'PRD', 'product_name' => 'Test Product', 'product_type' => 'goods',
            'is_active' => true,
        ]);
    }

    private function seedAccountMappings(): void
    {
        $ar = ChartOfAccount::query()->create([
            'account_code' => '1200', 'account_name' => 'Piutang Usaha', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'is_active' => true,
        ]);
        AccountMapping::query()->create([
            'module' => 'sales', 'mapping_key' => 'accounts_receivable', 'account_id' => $ar->id,
            'is_active' => true,
        ]);

        $rev = ChartOfAccount::query()->create([
            'account_code' => '4100', 'account_name' => 'Pendapatan', 'account_type' => 'revenue',
            'normal_balance' => 'credit', 'is_active' => true,
        ]);
        AccountMapping::query()->create([
            'module' => 'sales', 'mapping_key' => 'sales.revenue', 'account_id' => $rev->id,
            'is_active' => true,
        ]);
        AccountMapping::query()->create([
            'module' => 'sales', 'mapping_key' => 'sales.discount', 'account_id' => $rev->id,
            'is_active' => true,
        ]);

        $tax = ChartOfAccount::query()->create([
            'account_code' => '2200', 'account_name' => 'PPN Keluaran', 'account_type' => 'liability',
            'normal_balance' => 'credit', 'is_active' => true,
        ]);
        AccountMapping::query()->create([
            'module' => 'sales', 'mapping_key' => 'sales.tax_output', 'account_id' => $tax->id,
            'is_active' => true,
        ]);
    }

    private function createDraftInvoice(array $ctx, string $date): SalesInvoice
    {
        $tenantDb = TenantDatabase::query()->where('company_id', $ctx['company']->id)->firstOrFail();
        $companyUser = CompanyUser::query()->where('company_id', $ctx['company']->id)->firstOrFail();

        app(TenantContext::class)->set($ctx['company'], $companyUser, $tenantDb);

        return app(SalesInvoiceService::class)->create([
            'customer_id' => Contact::query()->firstOrFail()->id,
            'invoice_date' => $date,
            'lines' => [[
                'product_id' => Product::query()->firstOrFail()->id,
                'description' => 'Test line',
                'quantity' => 1,
                'unit_price' => 10000,
            ]],
        ]);
    }

    private function setUpTenant(string $role = 'owner'): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'BP Test '.$user->id, 'slug' => 'bp-test-'.$user->id,
            'code' => 'BP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active', 'created_by' => $user->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);

        $tenantPath = database_path('tenants/test_bp_'.$company->id.'_'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath));
        File::put($tenantPath, '');
        $this->registerTenantFile($tenantPath);

        TenantDatabase::query()->create([
            'company_id' => $company->id, 'database_name' => basename($tenantPath),
            'database_path' => $tenantPath, 'driver' => 'sqlite', 'status' => 'active',
        ]);

        app(TenantConnectionManager::class)->connect($tenantPath);
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]);
        Sanctum::actingAs($user, ['*']);

        return ['user' => $user, 'company' => $company, 'headers' => ['X-Company-ID' => (string) $company->id]];
    }
}
