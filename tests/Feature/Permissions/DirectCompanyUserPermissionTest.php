<?php

namespace Tests\Feature\Permissions;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Permission;
use App\Shared\Models\User;
use App\Shared\Permission\EffectivePermissionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DirectCompanyUserPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_permissions_are_scoped_to_the_company_membership(): void
    {
        $user = User::factory()->create();
        $first = $this->company('first', $user);
        $second = $this->company('second', $user);
        $firstMembership = $this->membership($user, $first);
        $secondMembership = $this->membership($user, $second);
        $permission = Permission::query()->where('key', 'sales.invoices.view')->firstOrFail();
        $firstMembership->assignedPermissions()->attach($permission->id);

        $service = app(EffectivePermissionService::class);
        $this->assertTrue($service->hasPermission($firstMembership, 'sales.invoices.view'));
        $this->assertFalse($service->hasPermission($secondMembership, 'sales.invoices.view'));
        $this->assertSame('direct', $service->explainPermission($firstMembership, 'sales.invoices.view')['source']);
        $this->assertSame('not_assigned', $service->explainPermission($secondMembership, 'sales.invoices.view')['source']);
    }

    public function test_duplicate_direct_permission_is_rejected(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, $this->company('unique', $user));
        $permission = Permission::query()->where('key', 'sales.invoices.view')->firstOrFail();
        $membership->assignedPermissions()->attach($permission->id);

        $this->expectException(QueryException::class);
        $membership->assignedPermissions()->attach($permission->id);
    }

    public function test_migration_marks_existing_test_owners_and_admins(): void
    {
        $migration = require database_path('migrations/2026_10_28_000003_create_direct_company_user_permissions.php');
        $migration->down();
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $viewer = User::factory()->create();
        $company = $this->company('migration', $owner);
        $ownerId = DB::table('company_users')->insertGetId(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'viewer', 'status' => 'active']);
        $adminId = DB::table('company_users')->insertGetId(['company_id' => $company->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
        $viewerId = DB::table('company_users')->insertGetId(['company_id' => $company->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'status' => 'active']);

        $migration->up();

        $this->assertDatabaseHas('company_users', ['id' => $ownerId, 'is_owner' => true, 'full_access' => true]);
        $this->assertDatabaseHas('company_users', ['id' => $adminId, 'is_owner' => false, 'full_access' => true]);
        $this->assertDatabaseHas('company_users', ['id' => $viewerId, 'is_owner' => false, 'full_access' => false]);
    }

    private function company(string $suffix, User $user): Company
    {
        return Company::query()->create([
            'name' => 'Direct '.$suffix, 'slug' => 'direct-'.$suffix, 'code' => 'DIRECT-'.$suffix,
            'status' => 'active', 'created_by' => $user->id,
        ]);
    }

    private function membership(User $user, Company $company): CompanyUser
    {
        return CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => 'admin', 'status' => 'active', 'full_access' => false, 'is_owner' => false,
        ]);
    }
}
