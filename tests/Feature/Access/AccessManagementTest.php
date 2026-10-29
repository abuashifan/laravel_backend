<?php

namespace Tests\Feature\Access;

use App\Shared\Models\ActivityLog;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyInvitation;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Role;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_endpoint_requires_auth_and_company_context(): void
    {
        $this->getJson('/api/access/company-users')->assertStatus(401);

        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user, ['*']);
        $this->getJson('/api/access/company-users')->assertStatus(422);
    }

    public function test_direct_permissions_can_be_read_updated_copied_and_audited(): void
    {
        $ctx = $this->companyContext();
        $target = $this->companyUser($ctx['company'], 'staff@example.test', 'viewer');
        $source = $this->companyUser($ctx['company'], 'source@example.test', 'viewer');
        $this->getJson('/api/access/users/'.$target->id.'/permissions', $ctx['headers'])->assertOk()->assertJsonStructure(['data' => ['full_access', 'is_owner', 'permissions', 'catalog' => ['modules', 'blocked_by_plan_keys']]]);
        $this->putJson('/api/access/users/'.$target->id.'/permissions', ['full_access' => false, 'permissions' => ['sales.invoices.view']], $ctx['headers'])->assertOk()->assertJsonPath('data.permissions.0', 'sales.invoices.view');
        $this->postJson('/api/access/users/'.$source->id.'/copy-access', ['source_company_user_id' => $target->id], $ctx['headers'])->assertOk()->assertJsonPath('data.permissions.0', 'sales.invoices.view');
        $log = ActivityLog::query()->where('action', 'access.permissions.update')->firstOrFail();
        $this->assertSame(['sales.invoices.view'], $log->properties['new_values']['permissions']);
        $this->assertArrayHasKey('old_values', $log->properties);
        $this->getJson('/api/access/roles', $ctx['headers'])->assertNotFound();
        $this->putJson('/api/access/users/'.$target->id.'/permissions', ['full_access' => false, 'permissions' => ['not.a.permission']], $ctx['headers'])->assertStatus(422);
    }

    public function test_owner_and_last_manager_are_protected_and_self_escalation_is_denied(): void
    {
        $ctx = $this->companyContext();
        $owner = $ctx['company_user'];
        $manager = $this->companyUser($ctx['company'], 'manager@example.test', 'viewer');
        $this->assignTestPermissions($manager, ['access.users.view', 'access.users.edit', 'access.users.delete']);
        Sanctum::actingAs($manager->user, ['*']);
        $this->patchJson('/api/access/company-users/'.$owner->id.'/deactivate', [], $ctx['headers'])->assertStatus(422)->assertJsonPath('code', 'OWNER_PROTECTED');
        $this->putJson('/api/access/users/'.$owner->id.'/permissions', ['full_access' => false, 'permissions' => []], $ctx['headers'])->assertStatus(422)->assertJsonPath('code', 'OWNER_ACCESS_REQUIRED');
        $this->putJson('/api/access/users/'.$manager->id.'/permissions', ['full_access' => true, 'permissions' => []], $ctx['headers'])->assertStatus(422)->assertJsonPath('code', 'SELF_ACCESS_INCREASE_NOT_ALLOWED');
        $owner->forceFill(['status' => 'inactive'])->save();
        $this->putJson('/api/access/users/'.$manager->id.'/permissions', ['full_access' => false, 'permissions' => []], $ctx['headers'])->assertStatus(422)->assertJsonPath('code', 'LAST_ACCESS_MANAGER_REQUIRED');
        $this->assertTrue($manager->assignedPermissions()->where('permissions.key', 'access.users.edit')->exists());
    }

    public function test_invitations_apply_permissions_to_the_matching_account(): void
    {
        $ctx = $this->companyContext();
        $invitee = User::factory()->create(['email' => 'invitee@example.test', 'status' => 'active']);
        $invite = $this->postJson('/api/access/invitations', ['email' => $invitee->email, 'full_access' => false, 'permissions' => ['sales.invoices.view']], $ctx['headers'])->assertCreated()->json('data');
        $other = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($other, ['*']);
        $this->postJson('/api/invitations/'.$invite['token'].'/accept')->assertForbidden();
        Sanctum::actingAs($invitee, ['*']);
        $this->postJson('/api/invitations/'.$invite['token'].'/accept')->assertOk();
        $member = CompanyUser::query()->where('company_id', $ctx['company']->id)->where('user_id', $invitee->id)->firstOrFail();
        $this->assertFalse($member->full_access);
        $this->assertTrue($member->assignedPermissions()->where('permissions.key', 'sales.invoices.view')->exists());
        $this->postJson('/api/invitations/'.$invite['token'].'/accept')->assertStatus(422);
    }

    public function test_company_scope_and_copy_to_self_do_not_bypass_protection(): void
    {
        $first = $this->companyContext();
        $source = $this->companyUser($first['company'], 'scope-source@example.test', 'admin');
        $second = $this->companyContext();
        $target = $this->companyUser($second['company'], 'scope-target@example.test', 'viewer');
        $this->getJson('/api/access/users/'.$source->id.'/permissions', $second['headers'])->assertNotFound();
        $this->postJson('/api/access/users/'.$target->id.'/copy-access', ['source_company_user_id' => $source->id], $second['headers'])->assertNotFound();
        $manager = $this->companyUser($second['company'], 'self-copy@example.test', 'viewer');
        $this->assignTestPermissions($manager, ['access.users.edit']);
        Sanctum::actingAs($manager->user, ['*']);
        $this->postJson('/api/access/users/'.$manager->id.'/copy-access', ['source_company_user_id' => $second['company_user']->id], $second['headers'])->assertStatus(422)->assertJsonPath('code', 'SELF_ACCESS_INCREASE_NOT_ALLOWED');
    }

    public function test_invitation_rejects_expired_revoked_and_full_quota(): void
    {
        $ctx = $this->companyContext();
        $invitee = User::factory()->create(['email' => 'quota@example.test', 'status' => 'active']);
        $invite = $this->postJson('/api/access/invitations', ['email' => $invitee->email, 'full_access' => true, 'permissions' => []], $ctx['headers'])->assertCreated()->json('data');
        $ctx['company_user']->user->forceFill(['extra_users' => 0])->save();
        Sanctum::actingAs($invitee, ['*']);
        $this->postJson('/api/invitations/'.$invite['token'].'/accept')->assertStatus(422)->assertJsonPath('code', 'USER_QUOTA_EXCEEDED');
        CompanyInvitation::query()->whereKey($invite['id'])->update(['expires_at' => now()->subDay()]);
        $this->postJson('/api/invitations/'.$invite['token'].'/accept')->assertStatus(422)->assertJsonPath('code', 'INVITATION_INVALID');
        CompanyInvitation::query()->whereKey($invite['id'])->update(['status' => 'revoked', 'expires_at' => now()->addDay()]);
        $this->postJson('/api/invitations/'.$invite['token'].'/accept')->assertStatus(422)->assertJsonPath('code', 'INVITATION_INVALID');
        $this->assertDatabaseMissing('company_users', ['company_id' => $ctx['company']->id, 'user_id' => $invitee->id]);
    }

    public function test_invitation_lifecycle_prevents_duplicate_active_invitation_and_is_audited(): void
    {
        $ctx = $this->companyContext();

        $invitation = $this->postJson('/api/access/invitations', [
            'email' => 'invitee@example.test',
            'full_access' => false,
            'permissions' => [],
        ], $ctx['headers'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data');

        $this->postJson('/api/access/invitations', [
            'email' => 'invitee@example.test',
            'full_access' => false,
            'permissions' => [],
        ], $ctx['headers'])->assertStatus(422)->assertJsonPath('code', 'ACTIVE_INVITATION_EXISTS');

        $this->postJson('/api/access/invitations/'.$invitation['id'].'/resend', [], $ctx['headers'])->assertOk();
        $this->postJson('/api/access/invitations/'.$invitation['id'].'/revoke', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertSame(3, ActivityLog::query()->where('module', 'access')->count());
    }

    public function test_company_user_safety_and_permission_denial_are_enforced(): void
    {
        $ctx = $this->companyContext();
        $this->patchJson('/api/access/company-users/'.$ctx['company_user']->id.'/deactivate', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OWNER_PROTECTED');

        $viewer = $this->companyUser($ctx['company'], 'readonly@example.test', 'viewer');
        Sanctum::actingAs($viewer->user, ['*']);
        $this->getJson('/api/access/company-users', $ctx['headers'])->assertStatus(403);
    }

    private function companyContext(): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['status' => 'active', 'extra_users' => 10]);
        $company = Company::query()->create([
            'name' => 'Access Company '.uniqid(),
            'slug' => 'access-company-'.uniqid(),
            'code' => 'ACC-'.uniqid(),
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $companyUser = CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'role_id' => Role::query()->where('slug', 'owner')->value('id'),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->tenant($company);
        Sanctum::actingAs($user, ['*']);

        return compact('company', 'companyUser') + [
            'company_user' => $companyUser,
            'headers' => ['X-Company-ID' => (string) $company->id],
        ];
    }

    private function companyUser(Company $company, string $email, string $role): CompanyUser
    {
        $user = User::factory()->create(['email' => $email, 'status' => 'active']);

        return CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'status' => 'active',
            'joined_at' => now(),
        ])->setRelation('user', $user);
    }

    private function tenant(Company $company): void
    {
        $path = database_path('tenants/test_access_'.$company->id.'_'.uniqid(getmypid().'_', true).'.sqlite');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');
        $this->registerTenantFile($path);

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => basename($path),
            'database_path' => $path,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);
    }
}
