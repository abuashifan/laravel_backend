<?php

namespace Tests\Feature\Settings;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanySettingTest extends TestCase
{
    use RefreshDatabase;

    private array $tenantFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tenantFiles as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        parent::tearDown();
    }

    public function test_unauthenticated_user_cannot_get_company_settings(): void
    {
        $this->getJson('/api/settings/company')->assertStatus(401);
    }

    public function test_authenticated_user_cannot_get_company_settings_without_x_company_id(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $this->getJson('/api/settings/company')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_authenticated_user_can_get_default_company_settings(): void
    {
        [$user, $company] = $this->seedAccessForUser();

        Sanctum::actingAs($user);

        $this->getJson('/api/settings/company', ['X-Company-ID' => (string) $company->id])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.accounting.base_currency', 'IDR')
            ->assertJsonPath('data.accounting.transaction_workflow_mode', 'simple_auto_post')
            ->assertJsonPath('data.accounting.auto_post_transactions', true)
            ->assertJsonPath('data.accounting.hide_voided_transactions', true)
            ->assertJsonPath('data.modules.sales_enabled', true)
            ->assertJsonPath('data.modules.inventory_enabled', false);
    }

    public function test_authenticated_company_user_can_get_workflow_settings_without_settings_permission(): void
    {
        [$user, $company] = $this->seedAccessForUser('warehouse');

        Sanctum::actingAs($user);

        $this->getJson('/api/settings/company/workflow', ['X-Company-ID' => (string) $company->id])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.transaction_workflow_mode', 'simple_auto_post')
            ->assertJsonPath('data.auto_post_transactions', true)
            ->assertJsonPath('data.approval_enabled', false)
            ->assertJsonPath('data.allow_void_transactions', true);
    }

    public function test_user_cannot_access_another_company_settings(): void
    {
        [$userA] = $this->seedAccessForUser();
        [, $companyB] = $this->seedAccessForUser();

        Sanctum::actingAs($userA);

        $this->getJson('/api/settings/company', ['X-Company-ID' => (string) $companyB->id])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_authenticated_user_can_update_accounting_settings(): void
    {
        [$user, $company] = $this->seedAccessForUser();

        Sanctum::actingAs($user);

        $this->patchJson(
            '/api/settings/company/accounting',
            [
                'transaction_workflow_mode' => 'draft_then_post',
                'auto_post_transactions' => false,
            ],
            ['X-Company-ID' => (string) $company->id]
        )
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.accounting.transaction_workflow_mode', 'draft_then_post')
            ->assertJsonPath('data.accounting.auto_post_transactions', false);
    }

    public function test_invalid_workflow_mode_rejected(): void
    {
        [$user, $company] = $this->seedAccessForUser();

        Sanctum::actingAs($user);

        $this->patchJson(
            '/api/settings/company/accounting',
            [
                'transaction_workflow_mode' => 'invalid_mode',
            ],
            ['X-Company-ID' => (string) $company->id]
        )
            ->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function seedAccessForUser(string $role = 'owner'): array
    {
        $user = User::factory()->create(['status' => 'active']);

        $companySeed = (int) $user->id + 200;
        $company = Company::query()->create([
            'name' => 'Company '.$companySeed,
            'slug' => 'company-'.$companySeed,
            'code' => 'CMP-'.str_pad((string) $companySeed, 6, '0', STR_PAD_LEFT),
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

        $databaseName = 'test_settings_'.$companySeed.'.sqlite';
        $databasePath = database_path('tenants/'.$databaseName);
        $this->ensureTenantFile($databasePath);

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => $databaseName,
            'database_path' => $databasePath,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        return [$user, $company];
    }

    private function ensureTenantFile(string $path): void
    {
        $dir = dirname($path);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        if (! File::exists($path)) {
            File::put($path, '');
        }

        $this->tenantFiles[] = $path;
    }
}
