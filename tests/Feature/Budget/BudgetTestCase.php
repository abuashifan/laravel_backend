<?php

namespace Tests\Feature\Budget;

use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Project;
use App\Modules\Settings\Services\CompanySettingService;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

        $companyUser = CompanyUser::query()->create([
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
        $this->registerTenantFile($tenantPath);

        $tenantDatabase = TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => basename($tenantPath),
            'database_path' => $tenantPath,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        app(TenantConnectionManager::class)->connect($tenantPath);

        // Di test yang memanggil service langsung (tanpa request HTTP) tidak ada
        // middleware tenant yang mengisi konteks, sehingga `companyId()` null dan
        // setiap query `where('company_id', null)` diam-diam kosong. Test lewat
        // HTTP tetap aman: middleware mengisi ulang konteksnya per request.
        app(TenantContext::class)->set($company, $companyUser, $tenantDatabase);

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

        Sanctum::actingAs($user, ['*']);

        return [
            'user' => $user,
            'company' => $company,
            'headers' => ['X-Company-ID' => (string) $company->id],
            'dept' => $dept,
            'account' => $account,
        ];
    }

    protected function makeAccount(string $code, string $name, string $type = 'expense'): ChartOfAccount
    {
        return ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $type === 'revenue' ? 'credit' : 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);
    }

    protected function makeDepartment(string $code, string $name, ?int $parentId = null): Department
    {
        return Department::query()->create([
            'parent_id' => $parentId,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    protected function makeProject(string $code, string $name): Project
    {
        return Project::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    /**
     * Satu baris jurnal ter-post. Actual anggaran selalu lahir dari sini —
     * modul Budget tidak punya ledger sendiri.
     *
     * @param  array<string,mixed>  $overrides  mis. ['status' => 'draft', 'is_obsolete' => true]
     */
    protected function postJournalLine(
        int $accountId,
        string $date,
        float $debit = 0,
        float $credit = 0,
        ?int $departmentId = null,
        ?int $projectId = null,
        array $overrides = [],
    ): void {
        $journalId = DB::connection('tenant')->table('journal_entries')->insertGetId(array_merge([
            'journal_number' => 'JV-'.str_pad((string) (self::$journalCounter++), 6, '0', STR_PAD_LEFT),
            'journal_date' => $date,
            'status' => 'posted',
            'is_obsolete' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        DB::connection('tenant')->table('journal_entry_lines')->insert([
            'journal_entry_id' => $journalId,
            'account_id' => $accountId,
            'department_id' => $departmentId,
            'project_id' => $projectId,
            'debit' => $debit,
            'credit' => $credit,
            'line_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static int $journalCounter = 1;
}
