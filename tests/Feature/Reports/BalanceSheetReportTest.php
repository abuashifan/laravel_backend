<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Department;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Project;
use App\Models\TenantDatabase;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Feature\Journal\JournalTestCase;

class BalanceSheetReportTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access_balance_sheet(): void
    {
        $res = $this->getJson('/api/reports/balance-sheet');
        $res->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'owner');

        $res = $this->getJson('/api/reports/balance-sheet?as_of_date=2026-01-31');
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'X_COMPANY_ID_REQUIRED');
    }

    public function test_user_without_reports_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');

        $res = $this->getJson('/api/reports/balance-sheet?as_of_date=2026-01-31', $ctx['headers']);
        $res->assertStatus(403);
        $res->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_validates_required_as_of_date(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $this->getJson('/api/reports/balance-sheet', $ctx['headers'])->assertStatus(422);
    }

    public function test_balance_sheet_calculates_assets_liabilities_equity_and_current_year_profit_loss_and_excludes_non_reportable_statuses_and_filters_dimensions(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $cashId = (int) $ctx['accounts']['debit'];
        $revenueId = (int) $ctx['accounts']['credit'];

        $capital = ChartOfAccount::query()->create([
            'account_code' => '3000',
            'account_name' => 'Capital',
            'account_type' => 'equity',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $liabilityZero = ChartOfAccount::query()->create([
            'account_code' => '2000',
            'account_name' => 'Liability',
            'account_type' => 'liability',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $deptA = Department::query()->create(['code' => 'A', 'name' => 'Dept A', 'is_active' => true]);
        $deptB = Department::query()->create(['code' => 'B', 'name' => 'Dept B', 'is_active' => true]);
        $projA = Project::query()->create(['code' => 'PA', 'name' => 'Project A', 'status' => 'active', 'is_active' => true]);

        // Balanced posted journal:
        // debit cash 10,000,000
        // credit capital 2,000,000
        // credit revenue 8,000,000
        $j = JournalEntry::query()->create([
            'journal_number' => 'JV-BS-1',
            'journal_date' => '2026-01-31',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $j->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 10000000, 'credit' => 0, 'line_order' => 1, 'department_id' => $deptA->id, 'project_id' => $projA->id],
            ['account_id' => $capital->id, 'debit' => 0, 'credit' => 2000000, 'line_order' => 2, 'department_id' => $deptA->id, 'project_id' => $projA->id],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 8000000, 'line_order' => 3, 'department_id' => $deptA->id, 'project_id' => $projA->id],
        ]);

        // Excluded: draft
        $jDraft = JournalEntry::query()->create([
            'journal_number' => 'JV-BS-DRF',
            'journal_date' => '2026-01-31',
            'status' => 'draft',
            'is_obsolete' => false,
        ]);
        $jDraft->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 9999999, 'credit' => 0, 'line_order' => 1],
        ]);

        // Excluded: approved
        $jApp = JournalEntry::query()->create([
            'journal_number' => 'JV-BS-APP',
            'journal_date' => '2026-01-31',
            'status' => 'approved',
            'is_obsolete' => false,
        ]);
        $jApp->lines()->createMany([
            ['account_id' => $capital->id, 'debit' => 0, 'credit' => 8888888, 'line_order' => 1],
        ]);

        // Excluded: void
        $jVoid = JournalEntry::query()->create([
            'journal_number' => 'JV-BS-VOID',
            'journal_date' => '2026-01-31',
            'status' => 'void',
            'is_obsolete' => false,
        ]);
        $jVoid->lines()->createMany([
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 7777777, 'line_order' => 1],
        ]);

        // Excluded: obsolete
        $jObs = JournalEntry::query()->create([
            'journal_number' => 'JV-BS-OBS',
            'journal_date' => '2026-01-31',
            'status' => 'posted',
            'is_obsolete' => true,
        ]);
        $jObs->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 6666666, 'credit' => 0, 'line_order' => 1],
        ]);

        // Another dept B journal should be excluded by deptA filter
        $jB = JournalEntry::query()->create([
            'journal_number' => 'JV-BS-DEP-B',
            'journal_date' => '2026-01-31',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jB->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 111, 'credit' => 0, 'line_order' => 1, 'department_id' => $deptB->id],
            ['account_id' => $capital->id, 'debit' => 0, 'credit' => 111, 'line_order' => 2, 'department_id' => $deptB->id],
        ]);

        $res = $this->getJson('/api/reports/balance-sheet?as_of_date=2026-01-31&department_id='.$deptA->id.'&project_id='.$projA->id, $ctx['headers'])
            ->assertStatus(200);

        $res->assertJsonPath('data.valid', true);
        $this->assertSame(10000000.0, (float) $res->json('data.totals.total_assets'));
        $this->assertSame(0.0, (float) $res->json('data.totals.total_liabilities'));
        $this->assertSame(8000000.0, (float) $res->json('data.totals.current_year_profit_or_loss'));
        $this->assertSame(10000000.0, (float) $res->json('data.totals.total_equity'));
        $this->assertSame(10000000.0, (float) $res->json('data.totals.total_liabilities_and_equity'));
        $this->assertTrue((bool) $res->json('data.totals.is_balanced'));

        // include_zero_balance=0 should hide liabilityZero account
        $flat = json_encode($res->json('data.sections'));
        $this->assertIsString($flat);
        $this->assertStringNotContainsString('"account_code":"2000"', (string) $flat);

        // include_zero_balance=1 should include liabilityZero account row
        $res2 = $this->getJson('/api/reports/balance-sheet?as_of_date=2026-01-31&include_zero_balance=1', $ctx['headers'])
            ->assertStatus(200);
        $flat2 = json_encode($res2->json('data.sections'));
        $this->assertStringContainsString('"account_code":"2000"', (string) $flat2);
    }

    public function test_user_cannot_access_another_company_tenant_balance_sheet(): void
    {
        $ctx1 = $this->setUpTenant(role: 'finance');

        $cashId = (int) $ctx1['accounts']['debit'];
        $revId = (int) $ctx1['accounts']['credit'];

        $capital = ChartOfAccount::query()->create([
            'account_code' => '3000',
            'account_name' => 'Capital',
            'account_type' => 'equity',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $j1 = JournalEntry::query()->create([
            'journal_number' => 'JV-C1',
            'journal_date' => '2026-01-31',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $j1->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 100, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $revId, 'debit' => 0, 'credit' => 80, 'line_order' => 2],
            ['account_id' => $capital->id, 'debit' => 0, 'credit' => 20, 'line_order' => 3],
        ]);

        $user = $ctx1['user'];
        $company2 = Company::query()->create([
            'name' => 'Company 2',
            'slug' => 'company-2-'.$user->id,
            'code' => 'CMP-'.str_pad((string) ($user->id + 1), 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        CompanyUser::query()->create([
            'company_id' => $company2->id,
            'user_id' => $user->id,
            'role' => 'finance',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $tenantPath2 = database_path('tenants/test_company_'.$company2->id.'_'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath2));
        File::put($tenantPath2, '');

        TenantDatabase::query()->create([
            'company_id' => $company2->id,
            'database_name' => basename($tenantPath2),
            'database_path' => $tenantPath2,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        app(TenantConnectionManager::class)->connect($tenantPath2);
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        $cash2 = ChartOfAccount::query()->create([
            'account_code' => '1000',
            'account_name' => 'Cash',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => true,
            'is_active' => true,
            'is_system_default' => false,
        ]);
        $rev2 = ChartOfAccount::query()->create([
            'account_code' => '4000',
            'account_name' => 'Revenue',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);
        $cap2 = ChartOfAccount::query()->create([
            'account_code' => '3000',
            'account_name' => 'Capital',
            'account_type' => 'equity',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $j2 = JournalEntry::query()->create([
            'journal_number' => 'JV-C2',
            'journal_date' => '2026-01-31',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $j2->lines()->createMany([
            ['account_id' => $cash2->id, 'debit' => 999, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $rev2->id, 'debit' => 0, 'credit' => 800, 'line_order' => 2],
            ['account_id' => $cap2->id, 'debit' => 0, 'credit' => 199, 'line_order' => 3],
        ]);

        $res = $this->getJson('/api/reports/balance-sheet?as_of_date=2026-01-31', $ctx1['headers'])
            ->assertStatus(200);

        $this->assertSame(100.0, (float) $res->json('data.totals.total_assets'));
    }
}
