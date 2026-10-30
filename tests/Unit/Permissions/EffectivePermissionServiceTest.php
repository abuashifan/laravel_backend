<?php

namespace Tests\Unit\Permissions;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Permission;
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
        $this->assertNotContains('sales.invoices.post', $permissions);
    }

    public function test_unassigned_permission_is_denied_until_assigned_directly(): void
    {
        [$companyUser, , $postPermission] = $this->seedAccessFixture();
        $service = app(EffectivePermissionService::class);

        $this->assertFalse($service->hasPermission($companyUser, 'sales.invoices.post'));
        $this->assertSame('not_assigned', $service->explainPermission($companyUser, 'sales.invoices.post')['source']);

        $companyUser->assignedPermissions()->attach($postPermission->id);
        $this->assertTrue($service->hasPermission($companyUser->refresh(), 'sales.invoices.post'));
        $this->assertSame('direct', $service->explainPermission($companyUser, 'sales.invoices.post')['source']);
    }

    public function test_full_access_grants_everything_and_revoking_it_falls_back_to_direct_permissions(): void
    {
        [$companyUser, $viewPermission] = $this->seedAccessFixture();
        $service = app(EffectivePermissionService::class);

        $companyUser->forceFill(['full_access' => true])->save();
        $this->assertTrue($service->hasPermission($companyUser->refresh(), 'sales.invoices.post'));
        $this->assertSame('full_access', $service->explainPermission($companyUser, 'sales.invoices.post')['source']);

        $companyUser->forceFill(['full_access' => false])->save();
        $companyUser->assignedPermissions()->detach($viewPermission->id);
        $this->assertFalse($service->hasPermission($companyUser->refresh(), 'sales.invoices.view'));
    }

    public function test_role_label_alone_does_not_grant_access_and_owner_flag_grants_full_access(): void
    {
        [$companyUser, $viewPermission] = $this->seedAccessFixture(role: 'finance');
        $companyUser->assignedPermissions()->detach($viewPermission->id);
        $service = app(EffectivePermissionService::class);

        // `role` hanya label sisa masa peran; tidak memberi izin apa pun.
        $this->assertFalse($service->hasPermission($companyUser->refresh(), 'sales.invoices.view'));
        $companyUser->forceFill(['is_owner' => true])->save();
        $this->assertTrue($service->hasPermission($companyUser->refresh(), 'sales.invoices.view'));
    }

    private function seedAccessFixture(string $role = 'staff'): array
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

        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'code' => 'CMP-TEST',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        // `full_access` diisi eksplisit: hook TestCase tidak menerjemahkan label peran.
        $companyUser = CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
            'full_access' => false,
            'is_owner' => false,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $companyUser->assignedPermissions()->attach($viewPermission->id);

        return [$companyUser, $viewPermission, $postPermission];
    }
}
