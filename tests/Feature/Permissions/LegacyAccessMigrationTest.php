<?php

namespace Tests\Feature\Permissions;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\CompanyUserPermissionOverride;
use App\Shared\Models\Permission;
use App\Shared\Models\Role;
use App\Shared\Models\User;
use App\Shared\Permission\EffectivePermissionService;
use App\Shared\Permission\LegacyAccessMigrationService;
use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyAccessMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_allow_and_deny_are_migrated_per_company_and_verifiable(): void
    {
        [$member, $role] = $this->memberWithRole();
        $cash = $this->permission('cash_bank.view');
        $invoice = $this->permission('sales.invoices.post');
        $role->permissions()->attach($cash->id);
        CompanyUserPermissionOverride::query()->create(['company_user_id' => $member->id, 'permission_id' => $cash->id, 'effect' => 'deny']);
        CompanyUserPermissionOverride::query()->create(['company_user_id' => $member->id, 'permission_id' => $invoice->id, 'effect' => 'allow']);

        $service = app(LegacyAccessMigrationService::class);
        $service->migrate($member);
        $service->migrate($member->refresh());
        $keys = $member->assignedPermissions()->pluck('permissions.key')->all();

        $this->assertContains('sales.invoices.post', $keys);
        $this->assertContains('sales.invoices.approve', $keys);
        $this->assertNotContains('cash_bank.view', $keys);
        $this->assertNotContains('cash_bank.receipts.view', $keys);
        $this->assertSame(['full_access' => true, 'is_owner' => true, 'missing' => [], 'extra' => []], $service->compare($member->refresh()));
        $this->artisan('access:verify-migration')->assertExitCode(0);

        $member->assignedPermissions()->detach($invoice->id);
        $this->artisan('access:verify-migration')->assertExitCode(1);
        $this->assertContains('sales.invoices.post', $service->compare($member->refresh())['missing']);
        $member->assignedPermissions()->attach($this->permission('purchase.bills.view')->id);
        $this->assertContains('purchase.bills.view', $service->compare($member->refresh())['extra']);
    }

    public function test_deny_wins_when_old_actions_map_to_the_same_new_permission(): void
    {
        [$member, $role] = $this->memberWithRole();
        $approve = $this->permission('sales.invoices.approve');
        $post = $this->permission('sales.invoices.post');
        $role->permissions()->attach([$approve->id, $post->id]);
        CompanyUserPermissionOverride::query()->create([
            'company_user_id' => $member->id, 'permission_id' => $post->id, 'effect' => 'deny',
        ]);

        app(LegacyAccessMigrationService::class)->migrate($member);

        $keys = $member->assignedPermissions()->pluck('permissions.key')->all();
        $this->assertNotContains('sales.invoices.post', $keys);
        $this->assertNotContains('sales.invoices.approve', $keys);
    }

    public function test_creator_and_legacy_admin_receive_full_access(): void
    {
        $creator = User::factory()->create();
        $admin = User::factory()->create();
        $company = $this->company($creator);
        $ownerMember = $this->membership($creator, $company, 'viewer');
        $adminMember = $this->membership($admin, $company, 'admin');
        $service = app(LegacyAccessMigrationService::class);

        $service->migrate($ownerMember);
        $service->migrate($adminMember);

        $this->assertTrue($ownerMember->refresh()->is_owner);
        $this->assertTrue($ownerMember->full_access);
        $this->assertFalse($adminMember->refresh()->is_owner);
        $this->assertTrue($adminMember->full_access);
        $this->artisan('access:verify-migration')->assertExitCode(0);
    }

    public function test_migration_retains_grants_hidden_by_current_package(): void
    {
        $this->seedTierPlans();
        $this->enforcePlanFeatures(true);
        $creator = $this->clientOn('basic');
        $user = User::factory()->create();
        $company = $this->company($creator);
        $role = Role::query()->create(['name' => 'Warehouse', 'slug' => 'migration-warehouse', 'is_system' => false, 'is_active' => true]);
        $role->permissions()->attach($this->permission('warehouses.create')->id);
        $member = $this->membership($user, $company, 'custom', $role->id);

        app(LegacyAccessMigrationService::class)->migrate($member);

        $this->assertContains('warehouses.create', $member->assignedPermissions()->pluck('permissions.key')->all());
        $this->assertContains('master_data.warehouses.create', $member->assignedPermissions()->pluck('permissions.key')->all());
        $this->assertFalse(app(EffectivePermissionService::class)->hasPermission($member->refresh(), 'warehouses.create'));
    }

    public function test_migration_processes_memberships_independently_across_companies(): void
    {
        $firstCreator = User::factory()->create();
        $secondCreator = User::factory()->create();
        $user = User::factory()->create();
        $first = $this->company($firstCreator);
        $second = $this->company($secondCreator);
        $role = Role::query()->create(['name' => 'Sales only', 'slug' => 'migration-sales', 'is_system' => false, 'is_active' => true]);
        $role->permissions()->attach($this->permission('sales.invoices.view')->id);
        $firstMember = $this->membership($user, $first, 'custom', $role->id);
        $secondMember = $this->membership($user, $second, 'noaccess');

        $migration = require database_path('migrations/2026_10_28_000004_migrate_direct_company_user_permissions.php');
        $migration->up();

        $service = app(EffectivePermissionService::class);
        $this->assertTrue($service->hasPermission($firstMember->refresh(), 'sales.invoices.view'));
        $this->assertFalse($service->hasPermission($secondMember->refresh(), 'sales.invoices.view'));
        $this->artisan('access:verify-migration')->assertExitCode(0);
    }

    /** @return array{CompanyUser, Role} */
    private function memberWithRole(): array
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $company = $this->company($creator);
        $role = Role::query()->create(['name' => 'Custom', 'slug' => 'migration-custom', 'is_system' => false, 'is_active' => true]);

        return [$this->membership($member, $company, 'custom', $role->id), $role];
    }

    private function company(User $creator): Company
    {
        return Company::query()->create([
            'name' => 'Migration', 'slug' => 'migration-'.$creator->id,
            'code' => 'MIG-'.$creator->id, 'status' => 'active', 'created_by' => $creator->id,
        ]);
    }

    private function membership(User $user, Company $company, string $role, ?int $roleId = null): CompanyUser
    {
        return CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id, 'role' => $role,
            'role_id' => $roleId, 'status' => 'active', 'full_access' => false, 'is_owner' => false,
        ]);
    }

    private function permission(string $key): Permission
    {
        return Permission::query()->firstOrCreate(['key' => $key], app(PermissionCatalogService::class)->fromKey($key));
    }
}
