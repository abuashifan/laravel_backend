<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Feature\Journal\JournalTestCase;

class GeneralLedgerApiTest extends JournalTestCase
{
    protected array $connectionsToTransact = ['sqlite'];

    public function test_unauthenticated_cannot_access_general_ledger(): void
    {
        $res = $this->getJson('/api/reports/general-ledger');
        $res->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'owner');

        $res = $this->getJson('/api/reports/general-ledger');
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'X_COMPANY_ID_REQUIRED');
    }

    public function test_user_without_reports_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');

        $res = $this->getJson('/api/reports/general-ledger', $ctx['headers']);
        $res->assertStatus(403);
        $res->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_user_with_reports_view_can_access_and_filters_work_and_other_company_isolated(): void
    {
        $ctx1 = $this->setUpTenant(role: 'finance', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
        ]);

        $coaList = $this->getJson('/api/master-data/chart-of-accounts', $ctx1['headers'])->assertStatus(200);
        $accountId = (int) ($coaList->json('data.0.id') ?? 0);
        $this->assertNotSame(0, $accountId);

        $creditAccountId = (int) ($coaList->json('data.1.id') ?? 0);
        $this->assertNotSame(0, $creditAccountId);

        // Create posted journal via API so tenant connection lifecycle matches real requests.
        $create = $this->postJson('/api/journals', [
            'journal_date' => '2026-03-01',
            'description' => 'Ledger Source',
            'lines' => [
                ['account_id' => $accountId, 'debit' => 10],
                ['account_id' => $creditAccountId, 'credit' => 10],
            ],
        ], $ctx1['headers'])->assertStatus(201);

        $journalId = (int) $create->json('data.id');

        $this->postJson("/api/journals/{$journalId}/void", ['reason' => 'Void for test'], $ctx1['headers'])
            ->assertStatus(200);

        // Create another posted journal that remains reportable
        $this->postJson('/api/journals', [
            'journal_date' => '2026-03-01',
            'description' => 'Ledger Included',
            'lines' => [
                ['account_id' => $accountId, 'debit' => 10],
                ['account_id' => $creditAccountId, 'credit' => 10],
            ],
        ], $ctx1['headers'])->assertStatus(201);

        // Warm up tenant connection lifecycle in this test environment.
        $this->getJson('/api/tenant-context-test', $ctx1['headers'])->assertStatus(200);
        $this->getJson('/api/master-data/chart-of-accounts', $ctx1['headers'])->assertStatus(200);
        $this->getJson('/api/master-data/chart-of-accounts/'.$accountId, $ctx1['headers'])->assertStatus(200);

        $res = $this->getJson('/api/reports/general-ledger?account_id='.$accountId.'&start_date=2026-03-01&end_date=2026-03-31', $ctx1['headers']);
        $res->assertStatus(200);
        $res->assertJsonPath('data.valid', true);
        $this->assertCount(1, (array) $res->json('data.lines'));

        $resBool = $this->getJson('/api/reports/general-ledger?account_id='.$accountId.'&start_date=2026-03-01&end_date=2026-03-31&include_opening_balance=true&include_zero_balance=false', $ctx1['headers']);
        $resBool->assertStatus(200);
        $resBool->assertJsonPath('data.valid', true);

        // Tenant isolation: create another company + tenant DB, same user, request must not see company1 journals.
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
            'role' => 'viewer',
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

        // Request with company2 header: should have zero lines.
        $res2 = $this->getJson('/api/reports/general-ledger?account_id='.$accountId.'&start_date=2026-03-01&end_date=2026-03-31', [
            'X-Company-ID' => (string) $company2->id,
        ]);
        $res2->assertStatus(200);
        $this->assertCount(0, (array) $res2->json('data.lines'));
    }
}
