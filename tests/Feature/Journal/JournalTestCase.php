<?php

namespace Tests\Feature\Journal;

use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Settings\Services\CompanySettingService;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class JournalTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, company: Company, headers: array<string,string>, tenant_path: string, accounts: array{debit:int,credit:int}}
     */
    protected function setUpTenant(string $role = 'owner', array $accountingSettingOverrides = []): array
    {
        $user = User::factory()->create(['status' => 'active']);

        $company = Company::query()->create([
            'name' => 'Company Journal',
            'slug' => 'company-journal-'.$user->id,
            'code' => 'CMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
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

        $tenantPath = database_path('tenants/test_company_'.$company->id.'_'.uniqid(getmypid().'_', true).'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath));

        if (! File::exists($tenantPath)) {
            File::put($tenantPath, '');
        }
        $this->registerTenantFile($tenantPath);

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

        // Configure accounting setting for deterministic workflow in tests.
        $settingService = app(CompanySettingService::class);
        if ($accountingSettingOverrides !== []) {
            $settingService->updateAccountingSetting($company, $accountingSettingOverrides);
        } else {
            $settingService->getOrCreateAccountingSetting($company);
        }

        // Seed minimal COA accounts in tenant DB.
        $cash = ChartOfAccount::query()->create([
            'account_code' => '1000',
            'account_name' => 'Cash',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => true,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $revenue = ChartOfAccount::query()->create([
            'account_code' => '4000',
            'account_name' => 'Revenue',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        Sanctum::actingAs($user, ['*']);

        return [
            'user' => $user,
            'company' => $company,
            'headers' => ['X-Company-ID' => (string) $company->id],
            'tenant_path' => $tenantPath,
            'accounts' => ['debit' => $cash->id, 'credit' => $revenue->id],
        ];
    }

    /**
     * Tenant POS untuk perusahaan uji, di berkas unik supaya aman dijalankan
     * paralel. Rute `company.access:pos` menolak (422) tanpa tenant ini.
     */
    protected function setUpPosTenant(Company $company): void
    {
        $posPath = database_path('tenants/test_pos_'.$company->id.'_'.uniqid(getmypid().'_', true).'.sqlite');
        File::ensureDirectoryExists(dirname($posPath));
        File::put($posPath, '');
        $this->registerTenantFile($posPath);

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'product' => TenantDatabase::PRODUCT_POS,
            'database_name' => basename($posPath),
            'database_path' => $posPath,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        app(TenantConnectionManager::class)->connect($posPath, 'pos');
        Artisan::call('migrate', ['--database' => 'pos', '--path' => 'database/migrations/tenant_pos', '--force' => true]);

        // Rute tulis POS butuh langganan Kasir pemilik (mode hanya-baca, Fase 2).
        if ($company->created_by && ! Subscription::query()->where('user_id', $company->created_by)->where('product', 'pos')->exists()) {
            Subscription::query()->create([
                'user_id' => $company->created_by,
                'plan_id' => Plan::query()->where('code', Plan::POS_CODE)->value('id'),
                'product' => 'pos',
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonth(),
                'billing_cycle' => 'monthly',
                'price' => 0,
            ]);
        }
    }
}
