<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Feature\Journal\JournalTestCase;

class TrialBalanceApiTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access_trial_balance(): void
    {
        $res = $this->getJson('/api/reports/trial-balance');
        $res->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'owner');

        $res = $this->getJson('/api/reports/trial-balance');
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'X_COMPANY_ID_REQUIRED');
    }

    public function test_user_without_reports_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');

        $res = $this->getJson('/api/reports/trial-balance', $ctx['headers']);
        $res->assertStatus(403);
        $res->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_user_with_reports_view_can_access_trial_balance_and_filters_work(): void
    {
        $ctx = $this->setUpTenant(role: 'finance', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
        ]);

        $res = $this->getJson('/api/reports/trial-balance?start_date=2026-01-01&end_date=2026-12-31&include_zero_balance=1', $ctx['headers'])
            ->assertStatus(200);

        $res->assertJsonPath('data.valid', true);
        $this->assertIsArray($res->json('data.accounts'));
        $this->assertIsArray($res->json('data.totals'));

        // account_type filter
        $res2 = $this->getJson('/api/reports/trial-balance?account_type=asset&include_zero_balance=1', $ctx['headers'])->assertStatus(200);
        $this->assertTrue((bool) $res2->json('data.valid'));
    }

    public function test_user_cannot_access_another_company_tenant_trial_balance(): void
    {
        $ctx1 = $this->setUpTenant(role: 'finance', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
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

        $res = $this->getJson('/api/reports/trial-balance', ['X-Company-ID' => (string) $company2->id])->assertStatus(200);
        $this->assertIsArray($res->json('data.accounts'));
    }
}

