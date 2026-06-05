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

class ProfitLossReportTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access_profit_loss(): void
    {
        $res = $this->getJson('/api/reports/profit-loss');
        $res->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'owner');

        $res = $this->getJson('/api/reports/profit-loss?start_date=2026-01-01&end_date=2026-01-31');
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'X_COMPANY_ID_REQUIRED');
    }

    public function test_user_without_reports_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');

        $res = $this->getJson('/api/reports/profit-loss?start_date=2026-01-01&end_date=2026-01-31', $ctx['headers']);
        $res->assertStatus(403);
        $res->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_validates_required_date_and_end_date_after_or_equal_start_date(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $this->getJson('/api/reports/profit-loss', $ctx['headers'])->assertStatus(422);

        $this->getJson('/api/reports/profit-loss?start_date=2026-01-31&end_date=2026-01-01', $ctx['headers'])
            ->assertStatus(422);
    }

    public function test_profit_loss_calculates_revenue_expense_and_net_profit_and_excludes_non_reportable_statuses(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $cashId = (int) $ctx['accounts']['debit'];
        $revenueId = (int) $ctx['accounts']['credit'];

        $expense = ChartOfAccount::query()->create([
            'account_code' => '5000',
            'account_name' => 'Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        // Posted revenue: debit cash 10,000,000; credit revenue 10,000,000
        $jRev = JournalEntry::query()->create([
            'journal_number' => 'JV-REV-1',
            'journal_date' => '2026-01-10',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jRev->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 10000000, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 10000000, 'line_order' => 2],
        ]);

        // Posted expense: debit expense 2,000,000; credit cash 2,000,000
        $jExp = JournalEntry::query()->create([
            'journal_number' => 'JV-EXP-1',
            'journal_date' => '2026-01-11',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jExp->lines()->createMany([
            ['account_id' => $expense->id, 'debit' => 2000000, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $cashId, 'debit' => 0, 'credit' => 2000000, 'line_order' => 2],
        ]);

        // Excluded: draft
        $jDraft = JournalEntry::query()->create([
            'journal_number' => 'JV-DRF-1',
            'journal_date' => '2026-01-12',
            'status' => 'draft',
            'is_obsolete' => false,
        ]);
        $jDraft->lines()->createMany([
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 9999999, 'line_order' => 1],
        ]);

        // Excluded: approved
        $jApproved = JournalEntry::query()->create([
            'journal_number' => 'JV-APP-1',
            'journal_date' => '2026-01-13',
            'status' => 'approved',
            'is_obsolete' => false,
        ]);
        $jApproved->lines()->createMany([
            ['account_id' => $expense->id, 'debit' => 8888888, 'credit' => 0, 'line_order' => 1],
        ]);

        // Excluded: void
        $jVoid = JournalEntry::query()->create([
            'journal_number' => 'JV-VOID-1',
            'journal_date' => '2026-01-14',
            'status' => 'void',
            'is_obsolete' => false,
        ]);
        $jVoid->lines()->createMany([
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 7777777, 'line_order' => 1],
        ]);

        // Excluded: obsolete
        $jObs = JournalEntry::query()->create([
            'journal_number' => 'JV-OBS-1',
            'journal_date' => '2026-01-15',
            'status' => 'posted',
            'is_obsolete' => true,
        ]);
        $jObs->lines()->createMany([
            ['account_id' => $expense->id, 'debit' => 6666666, 'credit' => 0, 'line_order' => 1],
        ]);

        $res = $this->getJson('/api/reports/profit-loss?start_date=2026-01-01&end_date=2026-01-31', $ctx['headers'])
            ->assertStatus(200);

        $res->assertJsonPath('data.valid', true);
        $this->assertSame(10000000.0, (float) $res->json('data.totals.total_revenue'));
        $this->assertSame(2000000.0, (float) $res->json('data.totals.total_expense'));
        $this->assertSame(8000000.0, (float) $res->json('data.totals.net_profit_or_loss'));
        $this->assertSame(8000000.0, (float) $res->json('data.totals.net_profit'));
        $this->assertSame(0.0, (float) $res->json('data.totals.net_loss'));
    }

    public function test_profit_loss_supports_loss_condition(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $cashId = (int) $ctx['accounts']['debit'];
        $revenueId = (int) $ctx['accounts']['credit'];

        $expense = ChartOfAccount::query()->create([
            'account_code' => '5000',
            'account_name' => 'Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        // Revenue 1,000,000
        $jRev = JournalEntry::query()->create([
            'journal_number' => 'JV-REV-LOSS',
            'journal_date' => '2026-01-10',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jRev->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 1000000, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 1000000, 'line_order' => 2],
        ]);

        // Expense 1,500,000
        $jExp = JournalEntry::query()->create([
            'journal_number' => 'JV-EXP-LOSS',
            'journal_date' => '2026-01-11',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jExp->lines()->createMany([
            ['account_id' => $expense->id, 'debit' => 1500000, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $cashId, 'debit' => 0, 'credit' => 1500000, 'line_order' => 2],
        ]);

        $res = $this->getJson('/api/reports/profit-loss?start_date=2026-01-01&end_date=2026-01-31', $ctx['headers'])
            ->assertStatus(200);

        $this->assertSame(-500000.0, (float) $res->json('data.totals.net_profit_or_loss'));
        $this->assertSame(0.0, (float) $res->json('data.totals.net_profit'));
        $this->assertSame(500000.0, (float) $res->json('data.totals.net_loss'));
    }

    public function test_profit_loss_supports_department_and_project_filters_and_include_zero_balance(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $cashId = (int) $ctx['accounts']['debit'];
        $revenueId = (int) $ctx['accounts']['credit'];

        $expense = ChartOfAccount::query()->create([
            'account_code' => '5000',
            'account_name' => 'Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $zeroExpense = ChartOfAccount::query()->create([
            'account_code' => '5001',
            'account_name' => 'Zero Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $deptA = Department::query()->create(['code' => 'A', 'name' => 'Dept A', 'is_active' => true]);
        $deptB = Department::query()->create(['code' => 'B', 'name' => 'Dept B', 'is_active' => true]);
        $projA = Project::query()->create(['code' => 'PA', 'name' => 'Project A', 'status' => 'active', 'is_active' => true]);

        // Dept A revenue 2,000,000
        $jA = JournalEntry::query()->create([
            'journal_number' => 'JV-A',
            'journal_date' => '2026-01-10',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jA->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 2000000, 'credit' => 0, 'line_order' => 1, 'department_id' => $deptA->id, 'project_id' => $projA->id],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 2000000, 'line_order' => 2, 'department_id' => $deptA->id, 'project_id' => $projA->id],
        ]);

        // Dept B expense 1,000,000 (should be excluded by deptA filter)
        $jB = JournalEntry::query()->create([
            'journal_number' => 'JV-B',
            'journal_date' => '2026-01-11',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jB->lines()->createMany([
            ['account_id' => $expense->id, 'debit' => 1000000, 'credit' => 0, 'line_order' => 1, 'department_id' => $deptB->id],
            ['account_id' => $cashId, 'debit' => 0, 'credit' => 1000000, 'line_order' => 2, 'department_id' => $deptB->id],
        ]);

        $resDeptA = $this->getJson('/api/reports/profit-loss?start_date=2026-01-01&end_date=2026-01-31&department_id='.$deptA->id, $ctx['headers'])
            ->assertStatus(200);
        $this->assertSame(2000000.0, (float) $resDeptA->json('data.totals.total_revenue'));
        $this->assertSame(0.0, (float) $resDeptA->json('data.totals.total_expense'));

        $resProjA = $this->getJson('/api/reports/profit-loss?start_date=2026-01-01&end_date=2026-01-31&project_id='.$projA->id, $ctx['headers'])
            ->assertStatus(200);
        $this->assertSame(2000000.0, (float) $resProjA->json('data.totals.total_revenue'));

        // include_zero_balance=0 should not show zeroExpense account
        $flat = json_encode($resDeptA->json('data.sections'));
        $this->assertIsString($flat);
        $this->assertStringNotContainsString('5001', (string) $flat);

        // include_zero_balance=1 should include zeroExpense account in expense section
        $resZero = $this->getJson('/api/reports/profit-loss?start_date=2026-01-01&end_date=2026-01-31&include_zero_balance=1', $ctx['headers'])
            ->assertStatus(200);
        $flat2 = json_encode($resZero->json('data.sections'));
        $this->assertStringContainsString('5001', (string) $flat2);
    }

    public function test_user_cannot_access_another_company_tenant_profit_loss(): void
    {
        $ctx1 = $this->setUpTenant(role: 'finance');

        // Seed some revenue in company 1 tenant
        $cashId = (int) $ctx1['accounts']['debit'];
        $revenueId = (int) $ctx1['accounts']['credit'];
        $j1 = JournalEntry::query()->create([
            'journal_number' => 'JV-C1',
            'journal_date' => '2026-01-10',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $j1->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 123, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 123, 'line_order' => 2],
        ]);

        // Create company 2 and tenant with its own data, but request using company 1 header should not see it.
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

        $j2 = JournalEntry::query()->create([
            'journal_number' => 'JV-C2',
            'journal_date' => '2026-01-10',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $j2->lines()->createMany([
            ['account_id' => $cash2->id, 'debit' => 999, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $rev2->id, 'debit' => 0, 'credit' => 999, 'line_order' => 2],
        ]);

        // Switch back to company 1 tenant connection by making a request with company 1 header.
        $res = $this->getJson('/api/reports/profit-loss?start_date=2026-01-01&end_date=2026-01-31', $ctx1['headers'])
            ->assertStatus(200);

        $this->assertSame(123.0, (float) $res->json('data.totals.total_revenue'));
    }
}

