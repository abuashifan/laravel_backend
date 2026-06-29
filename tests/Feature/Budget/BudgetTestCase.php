<?php

namespace Tests\Feature\Budget;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Department;
use App\Models\TenantDatabase;
use App\Models\User;
use App\Services\Settings\CompanySettingService;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class BudgetTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, company: Company, headers: array<string,string>, dept: Department, account: ChartOfAccount}
     */
    protected function setUpTenant(string $role = 'owner', array $accountingSettingOverrides = []): array
    {
        $user = User::factory()->create(['status' => 'active']);

        $company = Company::query()->create([
            'name' => 'Company Budget',
            'slug' => 'company-budget-'.$user->id,
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

        $settingService = app(CompanySettingService::class);
        if ($accountingSettingOverrides !== []) {
            $settingService->updateAccountingSetting($company, $accountingSettingOverrides);
        } else {
            $settingService->getOrCreateAccountingSetting($company);
        }

        $dept = Department::query()->create([
            'code' => 'OPS',
            'name' => 'Operational',
            'is_active' => true,
        ]);

        $account = ChartOfAccount::query()->create([
            'account_code' => '6000',
            'account_name' => 'Salaries Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        Sanctum::actingAs($user);

        return [
            'user' => $user,
            'company' => $company,
            'headers' => ['X-Company-ID' => (string) $company->id],
            'dept' => $dept,
            'account' => $account,
        ];
    }
}
