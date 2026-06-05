<?php

namespace Tests\Feature\Permissions;

use App\Models\Company;
use App\Models\CompanyAccountingSetting;
use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_get_permissions(): void
    {
        $this->getJson('/api/auth/permissions')->assertStatus(401);
    }

    public function test_authenticated_user_cannot_get_permissions_without_x_company_id(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/permissions')->assertStatus(422);
    }

    public function test_owner_role_receives_wildcard_permission(): void
    {
        [$user, $company] = $this->seedUserCompany(role: 'owner');
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/permissions', ['X-Company-ID' => (string) $company->id])
            ->assertStatus(200)
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.permission_mode', 'role_template')
            ->assertJson(fn ($json) => $json->where('data.permissions.0', '*')->etc());
    }

    public function test_admin_role_receives_settings_company_edit_permission(): void
    {
        [$user, $company] = $this->seedUserCompany(role: 'admin');
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/permissions', ['X-Company-ID' => (string) $company->id])
            ->assertStatus(200);

        $this->patchJson(
            '/api/settings/company/accounting',
            [
                'transaction_workflow_mode' => 'draft_then_post',
                'auto_post_transactions' => false,
            ],
            ['X-Company-ID' => (string) $company->id]
        )
            ->assertStatus(200);
    }

    public function test_viewer_does_not_have_settings_company_edit(): void
    {
        [$user, $company] = $this->seedUserCompany(role: 'viewer');
        Sanctum::actingAs($user);

        $this->patchJson(
            '/api/settings/company/accounting',
            [
                'transaction_workflow_mode' => 'draft_then_post',
                'auto_post_transactions' => false,
            ],
            ['X-Company-ID' => (string) $company->id]
        )
            ->assertStatus(403)
            ->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_sales_has_sales_create_but_not_purchase_create(): void
    {
        [$user, $company] = $this->seedUserCompany(role: 'sales');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/permissions', ['X-Company-ID' => (string) $company->id])
            ->assertStatus(200);

        $permissions = $response->json('data.permissions');
        $this->assertContains('sales.create', $permissions);
        $this->assertNotContains('purchase.create', $permissions);
    }

    public function test_purchasing_has_purchase_create_but_not_sales_create(): void
    {
        [$user, $company] = $this->seedUserCompany(role: 'purchasing');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/permissions', ['X-Company-ID' => (string) $company->id])
            ->assertStatus(200);

        $permissions = $response->json('data.permissions');
        $this->assertContains('purchase.create', $permissions);
        $this->assertNotContains('sales.create', $permissions);
    }

    public function test_warehouse_has_inventory_manage_but_not_sales_create(): void
    {
        [$user, $company] = $this->seedUserCompany(role: 'warehouse');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/permissions', ['X-Company-ID' => (string) $company->id])
            ->assertStatus(200);

        $permissions = $response->json('data.permissions');
        $this->assertContains('inventory.manage', $permissions);
        $this->assertNotContains('sales.create', $permissions);
    }

    public function test_user_cannot_get_permissions_for_another_users_company(): void
    {
        [$userA] = $this->seedUserCompany(role: 'owner');
        [, $companyB] = $this->seedUserCompany(role: 'owner');

        Sanctum::actingAs($userA);

        $this->getJson('/api/auth/permissions', ['X-Company-ID' => (string) $companyB->id])
            ->assertStatus(403);
    }

    public function test_unknown_role_has_no_permission(): void
    {
        [$user, $company] = $this->seedUserCompany(role: 'unknown');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/permissions', ['X-Company-ID' => (string) $company->id])
            ->assertStatus(200);

        $this->assertSame('unknown', $response->json('data.role'));
        $this->assertSame([], $response->json('data.permissions'));
    }

    public function test_permission_endpoint_includes_permission_mode(): void
    {
        [$user, $company] = $this->seedUserCompany(role: 'admin');
        Sanctum::actingAs($user);

        CompanyAccountingSetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            ['user_permission_mode' => 'manual_per_user']
        );

        $this->getJson('/api/auth/permissions', ['X-Company-ID' => (string) $company->id])
            ->assertStatus(200)
            ->assertJsonPath('data.permission_mode', 'manual_per_user');
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function seedUserCompany(string $role): array
    {
        $user = User::factory()->create(['status' => 'active']);

        $seed = (int) $user->id + 400;
        $company = Company::query()->create([
            'name' => 'Company '.$seed,
            'slug' => 'company-'.$seed,
            'code' => 'CMP-'.str_pad((string) $seed, 6, '0', STR_PAD_LEFT),
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

        $tenantPath = database_path('tenants/test_permission_'.$company->id.'_'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath));
        File::put($tenantPath, '');

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => basename($tenantPath),
            'database_path' => $tenantPath,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        return [$user, $company];
    }
}
