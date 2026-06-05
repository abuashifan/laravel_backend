<?php

namespace Tests\Feature\Journal;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Tenant\JournalEntry;
use App\Models\TenantDatabase;
use App\Models\User;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class JournalEntryTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_list_journals(): void
    {
        $res = $this->getJson('/api/journals');
        $res->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'owner');

        $res = $this->getJson('/api/journals');
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'X_COMPANY_ID_REQUIRED');
    }

    public function test_user_with_permission_can_create_draft_journal(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $payload = [
            'journal_date' => now()->toDateString(),
            'description' => 'Test Journal',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 100],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 100],
            ],
        ];

        $res = $this->postJson('/api/journals', $payload, $ctx['headers']);
        $res->assertStatus(201);
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('data.status', 'draft');
        $this->assertNotEmpty($res->json('data.journal_number'));
        $this->assertCount(2, $res->json('data.lines'));
    }

    public function test_duplicate_unbalanced_journal_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $payload = [
            'journal_date' => now()->toDateString(),
            'description' => 'Bad Journal',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 100],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 90],
            ],
        ];

        $res = $this->postJson('/api/journals', $payload, $ctx['headers']);
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_journal_with_invalid_account_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $payload = [
            'journal_date' => now()->toDateString(),
            'description' => 'Invalid Account',
            'lines' => [
                ['account_id' => 999999, 'debit' => 100],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 100],
            ],
        ];

        $res = $this->postJson('/api/journals', $payload, $ctx['headers']);
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_journal_index_hides_void_by_default(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $create = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'To Void',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 50],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 50],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $id = (int) $create->json('data.id');

        $this->postJson("/api/journals/{$id}/void", ['reason' => 'Mistake'], $ctx['headers'])
            ->assertStatus(200);

        $index = $this->getJson('/api/journals', $ctx['headers'])->assertStatus(200);
        $this->assertCount(0, $index->json('data'));

        $index2 = $this->getJson('/api/journals?include_void=true', $ctx['headers'])->assertStatus(200);
        $this->assertCount(1, $index2->json('data'));
    }

    public function test_journal_show_works(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $create = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'Show Journal',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 10],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 10],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $id = (int) $create->json('data.id');
        $show = $this->getJson("/api/journals/{$id}", $ctx['headers'])->assertStatus(200);
        $this->assertSame($id, (int) $show->json('data.id'));
        $this->assertCount(2, $show->json('data.lines'));
    }

    public function test_user_without_permission_cannot_create_journal(): void
    {
        $ctx = $this->setUpTenant(role: 'viewer', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $res = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'No Permission',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 1],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 1],
            ],
        ], $ctx['headers']);

        $res->assertStatus(403);
        $res->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_user_cannot_access_another_company_tenant_journal(): void
    {
        $ctx1 = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $create = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'Company 1',
            'lines' => [
                ['account_id' => $ctx1['accounts']['debit'], 'debit' => 5],
                ['account_id' => $ctx1['accounts']['credit'], 'credit' => 5],
            ],
        ], $ctx1['headers'])->assertStatus(201);

        $journalId = (int) $create->json('data.id');

        // Create another company & tenant DB, but same user has access; request should connect to company2 tenant so journal1 not found.
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

        // Now request with X-Company-ID = company2 should not see company1 journal.
        $res = $this->getJson("/api/journals/{$journalId}", ['X-Company-ID' => (string) $company2->id]);
        $res->assertStatus(404);

        // Sanity: company1 still accessible with correct header.
        $this->getJson("/api/journals/{$journalId}", $ctx1['headers'])->assertStatus(200);
    }
}

