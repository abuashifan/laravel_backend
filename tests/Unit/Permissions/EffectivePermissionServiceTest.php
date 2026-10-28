<?php

namespace Tests\Unit\Permissions;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\CompanyUserPermissionOverride;
use App\Shared\Models\Permission;
use App\Shared\Models\Role;
use App\Shared\Models\User;
use App\Shared\Permission\EffectivePermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EffectivePermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_gets_permission_from_direct_assignment(): void
    {
        [$companyUser] = $this->seedAccessFixture();

        $permissions = app(EffectivePermissionService::class)->getEffectivePermissionKeys($companyUser);

        $this->assertContains('sales.invoices.view', $permissions);
    }

    public function test_old_allow_override_does_not_grant_permission(): void
    {
        [$companyUser, , $postPermission] = $this->seedAccessFixture();

        CompanyUserPermissionOverride::query()->create([
            'company_user_id' => $companyUser->id,
            'permission_id' => $postPermission->id,
            'effect' => 'allow',
        ]);

        $service = app(EffectivePermissionService::class);

        $this->assertFalse($service->hasPermission($companyUser->refresh(), 'sales.invoices.post'));
        $companyUser->assignedPermissions()->attach($postPermission->id);
        $this->assertTrue($service->hasPermission($companyUser->refresh(), 'sales.invoices.post'));
        $this->assertSame('direct', $service->explainPermission($companyUser, 'sales.invoices.post')['source']);
    }

    public function test_full_access_is_not_restricted_by_legacy_deny_override(): void
    {
        [$companyUser, $viewPermission] = $this->seedAccessFixture();

        CompanyUserPermissionOverride::query()->create([
            'company_user_id' => $companyUser->id,
            'permission_id' => $viewPermission->id,
            'effect' => 'deny',
        ]);

        $companyUser->forceFill(['full_access' => true])->save();
        $service = app(EffectivePermissionService::class);
        $this->assertTrue($service->hasPermission($companyUser->refresh(), 'sales.invoices.view'));
        $this->assertSame('full_access', $service->explainPermission($companyUser, 'sales.invoices.view')['source']);
        $companyUser->forceFill(['full_access' => false])->save();
        $companyUser->assignedPermissions()->detach($viewPermission->id);
        $this->assertFalse($service->hasPermission($companyUser->refresh(), 'sales.invoices.view'));
    }

    public function test_role_alone_does_not_grant_access_and_owner_flag_grants_full_access(): void
    {
        [$companyUser, $viewPermission] = $this->seedAccessFixture();
        $companyUser->assignedPermissions()->detach($viewPermission->id);
        $service = app(EffectivePermissionService::class);

        $this->assertFalse($service->hasPermission($companyUser, 'sales.invoices.view'));
        $companyUser->forceFill(['is_owner' => true])->save();
        $this->assertTrue($service->hasPermission($companyUser->refresh(), 'sales.invoices.view'));
    }

    private function seedAccessFixture(): array
    {
        $viewPermission = Permission::query()->firstOrCreate(['key' => 'sales.invoices.view'], [
            'module' => 'sales',
            'feature' => 'invoices',
            'action' => 'view',
            'label' => 'Sales invoices view',
            'matrix_column' => 'daftar',
        ]);
        $postPermission = Permission::query()->firstOrCreate(['key' => 'sales.invoices.post'], [
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
            'full_access' => false,
            'is_owner' => false,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $companyUser->assignedPermissions()->attach($viewPermission->id);

        return [$companyUser, $viewPermission, $postPermission];
    }
}
