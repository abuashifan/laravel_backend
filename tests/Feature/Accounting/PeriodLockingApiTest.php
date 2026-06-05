<?php

namespace Tests\Feature\Accounting;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Tenant\ChartOfAccount;
use App\Models\TenantDatabase;
use App\Services\Accounting\FiscalYearService;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Feature\Journal\JournalTestCase;

class PeriodLockingApiTest extends JournalTestCase
{
    public function test_unauthorized_rejected_and_missing_x_company_id_rejected(): void
    {
        $this->getJson('/api/accounting/period-locks/status')->assertStatus(401);

        $ctx = $this->setUpTenant(role: 'finance');
        $res = $this->getJson('/api/accounting/period-locks/status');
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'X_COMPANY_ID_REQUIRED');
    }

    public function test_update_period_lock_works_with_permission_and_transactions_blocked_after_lock_and_another_tenant_unaffected(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $fy = app(FiscalYearService::class)->getOrCreateActiveFiscalYear($ctx['company'], 2026);

        $status = $this->getJson('/api/accounting/period-locks/status', $ctx['headers'])->assertStatus(200);
        $this->assertSame((int) $fy->id, (int) $status->json('data.active_fiscal_year.id'));

        $update = $this->patchJson('/api/accounting/period-locks', [
            'lock_until' => '2026-06-30',
            'override_reason' => 'Manual lock',
        ], $ctx['headers'])->assertStatus(200);

        $this->assertSame('2026-06-30', (string) $update->json('data.locked_until'));

        // create journal blocked on locked date
        $cashId = (int) $ctx['accounts']['debit'];
        $revId = (int) $ctx['accounts']['credit'];

        $blocked = $this->postJson('/api/journals', [
            'journal_date' => '2026-06-10',
            'description' => 'Blocked by lock',
            'lines' => [
                ['account_id' => $cashId, 'debit' => 10, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $revId, 'debit' => 0, 'credit' => 10, 'line_order' => 2],
            ],
        ], $ctx['headers'])->assertStatus(422);

        $blocked->assertJsonPath('code', 'TRANSACTION_PERIOD_LOCKED');

        // Another company unaffected (no lock)
        $user = $ctx['user'];
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
            'role' => 'owner',
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

        app(FiscalYearService::class)->getOrCreateActiveFiscalYear($company2, 2026);

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

        $headers2 = ['X-Company-ID' => (string) $company2->id];
        $allowed = $this->postJson('/api/journals', [
            'journal_date' => '2026-05-10',
            'description' => 'Allowed',
            'lines' => [
                ['account_id' => $cash2->id, 'debit' => 10, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $rev2->id, 'debit' => 0, 'credit' => 10, 'line_order' => 2],
            ],
        ], $headers2);

        $this->assertNotSame(422, $allowed->getStatusCode());
    }
}
