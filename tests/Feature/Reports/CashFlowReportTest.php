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

class CashFlowReportTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access_cash_flow(): void
    {
        $res = $this->getJson('/api/reports/cash-flow');
        $res->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'owner');

        $res = $this->getJson('/api/reports/cash-flow?start_date=2026-01-01&end_date=2026-01-31');
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'X_COMPANY_ID_REQUIRED');
    }

    public function test_user_without_reports_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');

        $res = $this->getJson('/api/reports/cash-flow?start_date=2026-01-01&end_date=2026-01-31', $ctx['headers']);
        $res->assertStatus(403);
        $res->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_validates_required_date_and_end_date_after_or_equal_start_date(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $this->getJson('/api/reports/cash-flow', $ctx['headers'])->assertStatus(422);

        $this->getJson('/api/reports/cash-flow?start_date=2026-01-31&end_date=2026-01-01', $ctx['headers'])
            ->assertStatus(422);
    }

    public function test_cash_flow_calculates_opening_cash_in_cash_out_and_ending_and_excludes_non_reportable_statuses_and_filters_dimensions_and_only_cash_accounts(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $cashId = (int) $ctx['accounts']['debit']; // is_cash_bank true
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

        $expense = ChartOfAccount::query()->create([
            'account_code' => '5000',
            'account_name' => 'Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $nonCashAsset = ChartOfAccount::query()->create([
            'account_code' => '1100',
            'account_name' => 'Non Cash Asset',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $deptA = Department::query()->create(['code' => 'A', 'name' => 'Dept A', 'is_active' => true]);
        $deptB = Department::query()->create(['code' => 'B', 'name' => 'Dept B', 'is_active' => true]);
        $projA = Project::query()->create(['code' => 'PA', 'name' => 'Project A', 'status' => 'active', 'is_active' => true]);

        // Opening before period: debit cash 5,000,000 credit capital 5,000,000 (Dept A)
        $jOpen = JournalEntry::query()->create([
            'journal_number' => 'JV-CF-OPEN',
            'journal_date' => '2025-12-31',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jOpen->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 5000000, 'credit' => 0, 'line_order' => 1, 'department_id' => $deptA->id],
            ['account_id' => $capital->id, 'debit' => 0, 'credit' => 5000000, 'line_order' => 2, 'department_id' => $deptA->id],
        ]);

        // Period cash in: debit cash 10,000,000 credit revenue 10,000,000 (Dept A + Proj A)
        $jIn = JournalEntry::query()->create([
            'journal_number' => 'JV-CF-IN',
            'journal_date' => '2026-01-10',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jIn->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 10000000, 'credit' => 0, 'line_order' => 1, 'department_id' => $deptA->id, 'project_id' => $projA->id],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 10000000, 'line_order' => 2, 'department_id' => $deptA->id, 'project_id' => $projA->id],
        ]);

        // Period cash out: debit expense 2,000,000 credit cash 2,000,000 (Dept A)
        $jOut = JournalEntry::query()->create([
            'journal_number' => 'JV-CF-OUT',
            'journal_date' => '2026-01-11',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jOut->lines()->createMany([
            ['account_id' => $expense->id, 'debit' => 2000000, 'credit' => 0, 'line_order' => 1, 'department_id' => $deptA->id],
            ['account_id' => $cashId, 'debit' => 0, 'credit' => 2000000, 'line_order' => 2, 'department_id' => $deptA->id],
        ]);

        // Non-cash account movement should not be included (posted in period)
        $jNonCash = JournalEntry::query()->create([
            'journal_number' => 'JV-CF-NONCASH',
            'journal_date' => '2026-01-12',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jNonCash->lines()->createMany([
            ['account_id' => $nonCashAsset->id, 'debit' => 123, 'credit' => 0, 'line_order' => 1],
        ]);

        // Excluded statuses on cash account:
        $jDraft = JournalEntry::query()->create([
            'journal_number' => 'JV-CF-DRF',
            'journal_date' => '2026-01-13',
            'status' => 'draft',
            'is_obsolete' => false,
        ]);
        $jDraft->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 999, 'credit' => 0, 'line_order' => 1],
        ]);

        $jVoid = JournalEntry::query()->create([
            'journal_number' => 'JV-CF-VOID',
            'journal_date' => '2026-01-14',
            'status' => 'void',
            'is_obsolete' => false,
        ]);
        $jVoid->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 888, 'credit' => 0, 'line_order' => 1],
        ]);

        $jObs = JournalEntry::query()->create([
            'journal_number' => 'JV-CF-OBS',
            'journal_date' => '2026-01-15',
            'status' => 'posted',
            'is_obsolete' => true,
        ]);
        $jObs->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 777, 'credit' => 0, 'line_order' => 1],
        ]);

        // Dept B cash movement should be excluded by deptA filter
        $jDeptB = JournalEntry::query()->create([
            'journal_number' => 'JV-CF-DEPTB',
            'journal_date' => '2026-01-10',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jDeptB->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 111, 'credit' => 0, 'line_order' => 1, 'department_id' => $deptB->id],
        ]);

        // Use department-only filter so opening (which has no project_id) is still included.
        $res = $this->getJson('/api/reports/cash-flow?start_date=2026-01-01&end_date=2026-01-31&department_id='.$deptA->id, $ctx['headers'])
            ->assertStatus(200);

        $res->assertJsonPath('data.valid', true);
        $this->assertSame(5000000.0, (float) $res->json('data.summary.opening_cash_balance'));
        $this->assertSame(10000000.0, (float) $res->json('data.summary.cash_in'));
        $this->assertSame(2000000.0, (float) $res->json('data.summary.cash_out'));
        $this->assertSame(8000000.0, (float) $res->json('data.summary.net_cash_flow'));
        $this->assertSame(13000000.0, (float) $res->json('data.summary.ending_cash_balance'));

        $flat = json_encode($res->json('data.accounts'));
        $this->assertIsString($flat);
        $this->assertStringNotContainsString('1100', (string) $flat);

        // include_account_breakdown=0 should return empty accounts
        $res2 = $this->getJson('/api/reports/cash-flow?start_date=2026-01-01&end_date=2026-01-31&include_account_breakdown=0', $ctx['headers'])
            ->assertStatus(200);
        $this->assertSame([], $res2->json('data.accounts'));
    }

    public function test_sections_classify_cash_flows_by_contra_account_cash_flow_section(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cashId = (int) $ctx['accounts']['debit'];

        // Revenue account with section = operating (seeded by migration for account_type=revenue,
        // but we set it explicitly here so the test is not coupled to migration order)
        $revenue = ChartOfAccount::query()->create([
            'account_code' => '4010',
            'account_name' => 'Revenue Ops',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
            'cash_flow_section' => 'operating',
        ]);

        // Equity account with section = financing
        $equity = ChartOfAccount::query()->create([
            'account_code' => '3010',
            'account_name' => 'Owner Capital',
            'account_type' => 'equity',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
            'cash_flow_section' => 'financing',
        ]);

        // Cash-in from operations: debit cash 10,000 credit revenue 10,000
        $jOps = JournalEntry::query()->create([
            'journal_number' => 'JV-CF-OPS',
            'journal_date' => '2026-01-10',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jOps->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 10000, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 10000, 'line_order' => 2],
        ]);

        // Cash-in from financing: debit cash 50,000 credit equity 50,000
        $jFin = JournalEntry::query()->create([
            'journal_number' => 'JV-CF-FIN',
            'journal_date' => '2026-01-15',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jFin->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 50000, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $equity->id, 'debit' => 0, 'credit' => 50000, 'line_order' => 2],
        ]);

        $res = $this->getJson('/api/reports/cash-flow?start_date=2026-01-01&end_date=2026-01-31', $ctx['headers'])
            ->assertStatus(200);

        $res->assertJsonPath('data.valid', true);

        $sections = $res->json('data.sections');
        $this->assertIsArray($sections, 'sections key must be present in response');

        $this->assertArrayHasKey('operating', $sections, 'operating section required');
        $this->assertArrayHasKey('financing', $sections, 'financing section required');

        $this->assertSame(10000.0, round((float) $sections['operating']['cash_in'], 2));
        $this->assertSame(0.0, round((float) $sections['operating']['cash_out'], 2));
        $this->assertSame(10000.0, round((float) $sections['operating']['net'], 2));

        $this->assertSame(50000.0, round((float) $sections['financing']['cash_in'], 2));
        $this->assertSame(0.0, round((float) $sections['financing']['cash_out'], 2));
        $this->assertSame(50000.0, round((float) $sections['financing']['net'], 2));
    }

    public function test_user_cannot_access_another_company_tenant_cash_flow(): void
    {
        $ctx1 = $this->setUpTenant(role: 'finance');

        $cashId = (int) $ctx1['accounts']['debit'];
        $revId = (int) $ctx1['accounts']['credit'];

        $j1 = JournalEntry::query()->create([
            'journal_number' => 'JV-C1',
            'journal_date' => '2026-01-10',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $j1->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 123, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $revId, 'debit' => 0, 'credit' => 123, 'line_order' => 2],
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

        $res = $this->getJson('/api/reports/cash-flow?start_date=2026-01-01&end_date=2026-01-31', $ctx1['headers'])
            ->assertStatus(200);

        $this->assertSame(123.0, (float) $res->json('data.summary.cash_in'));
    }
}
