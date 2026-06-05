<?php

namespace Tests\Feature\Accounting;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\FiscalYear;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use App\Models\TenantDatabase;
use App\Services\Accounting\FiscalYearService;
use App\Services\Tenant\TenantConnectionManager;
use App\Support\AccountMapping\AccountMappingKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Feature\Journal\JournalTestCase;

class FiscalYearLockingTest extends JournalTestCase
{
    public function test_cannot_create_or_edit_or_void_journal_in_closed_fiscal_year_and_reports_still_readable_and_other_company_unaffected(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $fy = app(FiscalYearService::class)->getOrCreateActiveFiscalYear($ctx['company'], 2026);

        $capital = ChartOfAccount::query()->create([
            'account_code' => '3000',
            'account_name' => 'Retained Earnings',
            'account_type' => 'equity',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);
        AccountMapping::query()->updateOrCreate(
            ['mapping_key' => AccountMappingKey::CLOSING_RETAINED_EARNINGS],
            ['module' => 'closing', 'account_id' => $capital->id, 'is_required' => true, 'is_active' => true]
        );

        $cashId = (int) $ctx['accounts']['debit'];
        $revenueId = (int) $ctx['accounts']['credit'];

        $j = JournalEntry::query()->create([
            'journal_number' => 'JV-CLOSE-1',
            'journal_date' => '2026-12-31',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $j->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 100, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $capital->id, 'debit' => 0, 'credit' => 20, 'line_order' => 2],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 80, 'line_order' => 3],
        ]);

        $this->getJson('/api/accounting/fiscal-years/'.$fy->id.'/closing-preview', $ctx['headers'])->assertStatus(200);
        $this->postJson('/api/accounting/fiscal-years/'.$fy->id.'/close', [], $ctx['headers'])->assertStatus(200);

        // create journal blocked
        $create = $this->postJson('/api/journals', [
            'journal_date' => '2026-05-10',
            'description' => 'Blocked',
            'lines' => [
                ['account_id' => $cashId, 'debit' => 10, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $revenueId, 'debit' => 0, 'credit' => 10, 'line_order' => 2],
            ],
        ], $ctx['headers']);

        // $create->dump();
        $create->assertStatus(422);
        $create->assertJsonPath('code', 'FISCAL_YEAR_CLOSED');

        // edit blocked
        $update = $this->patchJson('/api/journals/'.$j->id, [
            'description' => 'Try edit',
            'edit_reason' => 'test',
            'lines' => [
                ['account_id' => $cashId, 'debit' => 100, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $revenueId, 'debit' => 0, 'credit' => 100, 'line_order' => 2],
            ],
        ], $ctx['headers']);
        // $update->dump();
        $update->assertStatus(422);
        $update->assertJsonPath('code', 'FISCAL_YEAR_CLOSED');

        // void blocked
        $void = $this->postJson('/api/journals/'.$j->id.'/void', ['reason' => 'xxx'], $ctx['headers']);
        // $void->dump();
        $void->assertStatus(422);
        $void->assertJsonPath('code', 'FISCAL_YEAR_CLOSED');

        // Historical report still readable
        $tb = $this->getJson('/api/reports/trial-balance?start_date=2026-01-01&end_date=2026-12-31', $ctx['headers'])
            ->assertStatus(200);
        $tb->assertJsonPath('data.valid', true);

        // Another company unaffected: create another company with open fiscal year and can create journal
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
        $create2 = $this->postJson('/api/journals', [
            'journal_date' => '2026-05-10',
            'description' => 'Allowed',
            'lines' => [
                ['account_id' => $cash2->id, 'debit' => 10, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $rev2->id, 'debit' => 0, 'credit' => 10, 'line_order' => 2],
            ],
        ], $headers2);

        // $create2->dump();
        $this->assertNotSame(422, $create2->getStatusCode());
    }
}
