<?php

namespace Tests\Feature\Journal;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Tenant\ChartOfAccount;
use App\Models\TenantDatabase;
use App\Models\User;
use App\Services\Settings\CompanySettingService;
use App\Services\Tenant\TenantConnectionManager;
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

        $tenantPath = database_path('tenants/test_company_'.$company->id.'_'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath));

        if (! File::exists($tenantPath)) {
            File::put($tenantPath, '');
        }

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

        Sanctum::actingAs($user);

        return [
            'user' => $user,
            'company' => $company,
            'headers' => ['X-Company-ID' => (string) $company->id],
            'tenant_path' => $tenantPath,
            'accounts' => ['debit' => $cash->id, 'credit' => $revenue->id],
        ];
    }
}

