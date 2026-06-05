<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Feature\Journal\JournalTestCase;

class AccountLedgerDetailApiTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access_account_ledger_detail(): void
    {
        $res = $this->getJson('/api/reports/account-ledger/1');
        $res->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'owner');

        $res = $this->getJson('/api/reports/account-ledger/1');
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'X_COMPANY_ID_REQUIRED');
    }

    public function test_user_without_reports_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');

        $res = $this->getJson('/api/reports/account-ledger/1', $ctx['headers']);
        $res->assertStatus(403);
        $res->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_user_with_reports_view_can_access_account_ledger_and_invalid_account_returns_404(): void
    {
        $ctx = $this->setUpTenant(role: 'finance', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
        ]);

        $coaList = $this->getJson('/api/master-data/chart-of-accounts', $ctx['headers'])->assertStatus(200)->json('data');
        $accountId = (int) ($coaList[0]['id'] ?? 0);
        $creditAccountId = (int) ($coaList[1]['id'] ?? 0);

        $this->assertNotSame(0, $accountId);
        $this->assertNotSame(0, $creditAccountId);

        // Create one void journal and one posted journal (included).
        $create = $this->postJson('/api/journals', [
            'journal_date' => '2026-03-01',
            'description' => 'To Void',
            'lines' => [
                ['account_id' => $accountId, 'debit' => 10],
                ['account_id' => $creditAccountId, 'credit' => 10],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $jId = (int) $create->json('data.id');
        $this->postJson("/api/journals/{$jId}/void", ['reason' => 'void'], $ctx['headers'])->assertStatus(200);

        $this->postJson('/api/journals', [
            'journal_date' => '2026-03-01',
            'description' => 'Included',
            'lines' => [
                ['account_id' => $accountId, 'debit' => 10],
                ['account_id' => $creditAccountId, 'credit' => 10],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $res = $this->getJson('/api/reports/account-ledger/'.$accountId.'?start_date=2026-03-01&end_date=2026-03-31', $ctx['headers'])
            ->assertStatus(200);

        $res->assertJsonPath('data.valid', true);
        $res->assertJsonPath('data.account.id', $accountId);
        $this->assertIsArray($res->json('data.lines'));

        $bad = $this->getJson('/api/reports/account-ledger/999999', $ctx['headers']);
        $bad->assertStatus(404);
    }

    public function test_user_cannot_access_another_company_tenant_ledger(): void
    {
        $ctx1 = $this->setUpTenant(role: 'finance', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
        ]);

        $coaList = $this->getJson('/api/master-data/chart-of-accounts', $ctx1['headers'])->assertStatus(200)->json('data');
        $accountId = (int) ($coaList[0]['id'] ?? 0);

        // Create another company & tenant DB, same user; request should connect to other tenant so lines should be empty / account not found.
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

        $res = $this->getJson('/api/reports/account-ledger/'.$accountId, ['X-Company-ID' => (string) $company2->id]);
        $res->assertStatus(404);
    }
}

