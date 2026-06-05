<?php

namespace Tests\Unit\Permissions;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\CompanyUserPermissionOverride;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Permissions\EffectivePermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EffectivePermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_gets_permission_from_role_preset(): void
    {
        [$companyUser] = $this->seedAccessFixture();

        $permissions = app(EffectivePermissionService::class)->getEffectivePermissionKeys($companyUser);

        $this->assertContains('sales.invoices.view', $permissions);
    }

    public function test_allow_override_adds_permission_not_in_role(): void
    {
        [$companyUser, , $postPermission] = $this->seedAccessFixture();

        CompanyUserPermissionOverride::query()->create([
            'company_user_id' => $companyUser->id,
            'permission_id' => $postPermission->id,
            'effect' => 'allow',
        ]);

        $service = app(EffectivePermissionService::class);

        $this->assertTrue($service->hasPermission($companyUser->refresh(), 'sales.invoices.post'));
        $this->assertSame('user_override_allow', $service->explainPermission($companyUser, 'sales.invoices.post')['source']);
    }

    public function test_deny_override_removes_role_permission_and_reset_restores_it(): void
    {
        [$companyUser, $viewPermission] = $this->seedAccessFixture();

        CompanyUserPermissionOverride::query()->create([
            'company_user_id' => $companyUser->id,
            'permission_id' => $viewPermission->id,
            'effect' => 'deny',
        ]);

        $service = app(EffectivePermissionService::class);

        $this->assertFalse($service->hasPermission($companyUser->refresh(), 'sales.invoices.view'));

        $companyUser->permissionOverrides()->delete();

        $this->assertTrue($service->hasPermission($companyUser->refresh(), 'sales.invoices.view'));
    }

    private function seedAccessFixture(): array
    {
        $viewPermission = Permission::query()->create([
            'key' => 'sales.invoices.view',
            'module' => 'sales',
            'feature' => 'invoices',
            'action' => 'view',
            'label' => 'Sales invoices view',
            'matrix_column' => 'daftar',
        ]);
        $postPermission = Permission::query()->create([
            'key' => 'sales.invoices.post',
            'module' => 'sales',
            'feature' => 'invoices',
            'action' => 'post',
            'label' => 'Sales invoices post',
            'matrix_column' => 'persetujuan',
        ]);

        $role = Role::query()->create([
            'name' => 'Sales Viewer',
            'slug' => 'sales-viewer',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync([$viewPermission->id]);

        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'code' => 'CMP-TEST',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $companyUser = CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role->slug,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$companyUser, $viewPermission, $postPermission];
    }
}
