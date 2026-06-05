<?php

namespace Tests\Feature\Accounting;

use App\Models\Company;
use App\Models\CompanyAccountingSetting;
use App\Models\CompanyUser;
use App\Models\FiscalYear;
use App\Models\TenantDatabase;
use App\Models\User;
use App\Services\Accounting\AnnualClosingGateService;
use App\Services\Accounting\FiscalYearService;
use App\Services\Transactions\TransactionPolicyService;
use App\Services\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalYearDateGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_or_create_active_fiscal_year_creates_jan_dec_fiscal_year(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Company Test FY',
            'slug' => 'company-test-fy-'.$user->id,
            'code' => 'CMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $service = $this->app->make(FiscalYearService::class);

        $fy = $service->getOrCreateActiveFiscalYear($company, 2026);

        $this->assertSame(2026, $fy->year);
        $this->assertSame('2026-01-01', $fy->start_date->toDateString());
        $this->assertSame('2026-12-31', $fy->end_date->toDateString());
        $this->assertTrue($fy->is_active);
    }

    public function test_create_periods_for_fiscal_year_creates_12_accounting_periods(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Company Test Periods',
            'slug' => 'company-test-periods-'.$user->id,
            'code' => 'CMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $service = $this->app->make(FiscalYearService::class);

        $fy = $service->createFiscalYear($company, 2026);
        $service->createPeriodsForFiscalYear($fy);

        $this->assertCount(12, $fy->periods()->get());
    }

    public function test_date_inside_active_fiscal_year_is_allowed(): void
    {
        [$user, $company] = $this->seedAccess(role: 'owner');

        app(TenantContext::class)->set($company, CompanyUser::first(), TenantDatabase::first());

        $policy = $this->app->make(TransactionPolicyService::class);
        $result = $policy->canCreate('sales', '2026-06-10');

        $this->assertTrue($result->allowed());
    }

    public function test_date_before_active_fiscal_year_is_blocked(): void
    {
        [$user, $company] = $this->seedAccess(role: 'owner');
        $this->setActiveFiscalYear($company, 2026);

        app(TenantContext::class)->set($company, CompanyUser::first(), TenantDatabase::first());
        $policy = $this->app->make(TransactionPolicyService::class);

        $result = $policy->canCreate('sales', '2025-12-31');
        $this->assertTrue($result->denied());
        $this->assertSame('TRANSACTION_DATE_OUTSIDE_ACTIVE_FISCAL_YEAR', $result->toArray()['code']);
    }

    public function test_date_after_active_fiscal_year_is_blocked(): void
    {
        [$user, $company] = $this->seedAccess(role: 'owner');
        $this->setActiveFiscalYear($company, 2026);

        app(TenantContext::class)->set($company, CompanyUser::first(), TenantDatabase::first());
        $policy = $this->app->make(TransactionPolicyService::class);

        $result = $policy->canCreate('sales', '2027-01-01');
        $this->assertTrue($result->denied());
    }

    public function test_null_max_backdate_days_allows_backdate_inside_active_fiscal_year(): void
    {
        [$user, $company] = $this->seedAccess(role: 'owner');
        $this->setActiveFiscalYear($company, 2026);

        $setting = CompanyAccountingSetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            ['max_backdate_days' => null, 'allow_backdated_transactions' => true]
        );

        app(TenantContext::class)->set($company, CompanyUser::first(), TenantDatabase::first());

        $policy = $this->app->make(TransactionPolicyService::class);
        $result = $policy->canCreate('sales', '2026-01-05');

        $this->assertTrue($result->allowed());
    }

    public function test_null_max_future_days_allows_future_date_inside_active_fiscal_year(): void
    {
        [$user, $company] = $this->seedAccess(role: 'owner');
        $this->setActiveFiscalYear($company, 2026);

        CompanyAccountingSetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            ['max_future_days' => null, 'allow_future_transactions' => true]
        );

        app(TenantContext::class)->set($company, CompanyUser::first(), TenantDatabase::first());
        Carbon::setTestNow('2026-05-01');

        $policy = $this->app->make(TransactionPolicyService::class);
        $result = $policy->canCreate('sales', '2026-05-10');

        $this->assertTrue($result->allowed());
    }

    public function test_future_date_inside_active_fiscal_year_returns_warning_when_date_warning_enabled_true(): void
    {
        [$user, $company] = $this->seedAccess(role: 'owner');
        $this->setActiveFiscalYear($company, 2026);

        CompanyAccountingSetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            ['allow_future_transactions' => true, 'max_future_days' => null, 'date_warning_enabled' => true]
        );

        app(TenantContext::class)->set($company, CompanyUser::first(), TenantDatabase::first());
        Carbon::setTestNow('2026-05-01');

        $policy = $this->app->make(TransactionPolicyService::class);
        $result = $policy->canCreate('sales', '2026-05-10');

        $this->assertTrue($result->allowed());
        $this->assertTrue($result->isWarning());
    }

    public function test_different_period_inside_active_fiscal_year_returns_warning_when_date_warning_enabled_true(): void
    {
        [$user, $company] = $this->seedAccess(role: 'owner');
        $this->setActiveFiscalYear($company, 2026);

        CompanyAccountingSetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            ['date_warning_enabled' => true]
        );

        app(TenantContext::class)->set($company, CompanyUser::first(), TenantDatabase::first());
        Carbon::setTestNow('2026-05-01');

        $policy = $this->app->make(TransactionPolicyService::class);
        $result = $policy->canCreate('sales', '2026-06-01');

        $this->assertTrue($result->allowed());
        $this->assertTrue($result->isWarning());
        $this->assertSame('FUTURE_TRANSACTION_DATE_WARNING', $result->toArray()['code']);
    }

    public function test_closed_fiscal_year_date_is_blocked_read_only(): void
    {
        [$user, $company] = $this->seedAccess(role: 'owner');

        FiscalYear::query()->create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'closed',
            'is_active' => false,
        ]);

        app(TenantContext::class)->set($company, CompanyUser::first(), TenantDatabase::first());
        $policy = $this->app->make(TransactionPolicyService::class);
        $result = $policy->canEdit('sales', ['status' => 'posted', 'transaction_date' => '2026-05-17']);

        $this->assertTrue($result->denied());
        $this->assertSame('FISCAL_YEAR_CLOSED', $result->toArray()['code']);
    }

    public function test_next_fiscal_year_date_is_blocked_if_active_fiscal_year_not_closed(): void
    {
        [$user, $company] = $this->seedAccess(role: 'owner');
        $this->setActiveFiscalYear($company, 2026);

        app(TenantContext::class)->set($company, CompanyUser::first(), TenantDatabase::first());
        $policy = $this->app->make(TransactionPolicyService::class);
        $result = $policy->canCreate('sales', '2027-01-01');

        $this->assertTrue($result->denied());
        $this->assertSame('PREVIOUS_FISCAL_YEAR_NOT_CLOSED', $result->toArray()['code']);
    }

    public function test_creating_next_fiscal_year_is_blocked_if_previous_fiscal_year_not_closed(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Company Gate 3',
            'slug' => 'company-gate-3-'.$user->id,
            'code' => 'CMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $service = $this->app->make(FiscalYearService::class);
        $service->createFiscalYear($company, 2026, '2026-01-01', '2026-12-31');

        $this->expectException(\RuntimeException::class);
        $service->createFiscalYear($company, 2027, '2027-01-01', '2027-12-31');
    }

    public function test_annual_closing_gate_requires_closing_after_fiscal_year_end_date(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Company Gate 1',
            'slug' => 'company-gate-1-'.$user->id,
            'code' => 'CMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $service = $this->app->make(FiscalYearService::class);
        $gate = $this->app->make(AnnualClosingGateService::class);

        $service->createFiscalYear($company, 2026, '2026-01-01', '2026-12-31');

        $this->assertTrue($gate->closingRequired($company, '2027-01-02'));
    }

    public function test_annual_closing_gate_does_not_require_closing_monthly(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Company Gate 2',
            'slug' => 'company-gate-2-'.$user->id,
            'code' => 'CMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $service = $this->app->make(FiscalYearService::class);
        $gate = $this->app->make(AnnualClosingGateService::class);

        $service->createFiscalYear($company, 2026, '2026-01-01', '2026-12-31');

        $this->assertFalse($gate->closingRequired($company, '2026-02-01'));
    }

    public function test_transaction_policy_service_can_create_returns_date_warning_if_guard_warning(): void
    {
        [$user, $company] = $this->seedAccess(role: 'owner');
        $this->setActiveFiscalYear($company, 2026);

        CompanyAccountingSetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            ['allow_future_transactions' => true, 'max_future_days' => null, 'date_warning_enabled' => true]
        );

        app(TenantContext::class)->set($company, CompanyUser::first(), TenantDatabase::first());
        Carbon::setTestNow('2026-05-01');

        $policy = $this->app->make(TransactionPolicyService::class);
        $result = $policy->canCreate('sales', '2026-05-10');

        $this->assertTrue($result->allowed());
        $this->assertTrue($result->isWarning());
    }

    public function test_transaction_policy_service_can_edit_denies_if_transaction_date_is_in_closed_fiscal_year(): void
    {
        [$user, $company] = $this->seedAccess(role: 'owner');

        FiscalYear::query()->create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'closed',
            'is_active' => false,
        ]);

        app(TenantContext::class)->set($company, CompanyUser::first(), TenantDatabase::first());
        $policy = $this->app->make(TransactionPolicyService::class);
        $result = $policy->canEdit('sales', ['status' => 'posted', 'transaction_date' => '2026-05-17']);

        $this->assertTrue($result->denied());
        $this->assertSame('FISCAL_YEAR_CLOSED', $result->toArray()['code']);
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function seedAccess(string $role): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Company Test',
            'slug' => 'company-test-'.$user->id,
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

        $tenantDatabase = TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => 'company_'.str_pad((string) $company->id, 6, '0', STR_PAD_LEFT).'.sqlite',
            'database_path' => database_path('tenants/company_'.str_pad((string) $company->id, 6, '0', STR_PAD_LEFT).'.sqlite'),
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        CompanyAccountingSetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            [
                'block_outside_current_fiscal_year' => true,
                'date_warning_enabled' => true,
                'allow_backdated_transactions' => true,
                'max_backdate_days' => null,
                'allow_future_transactions' => true,
                'max_future_days' => null,
            ]
        );

        return [$user, $company];
    }

    private function setActiveFiscalYear(Company $company, int $year): FiscalYear
    {
        $service = $this->app->make(FiscalYearService::class);
        $fy = $service->createFiscalYear($company, $year);
        $service->createPeriodsForFiscalYear($fy);
        return $fy;
    }
}
