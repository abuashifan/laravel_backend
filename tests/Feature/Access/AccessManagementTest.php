<?php

namespace Tests\Feature\Access;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\TenantDatabase;
use App\Models\User;
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
        Sanctum::actingAs($user);
        $this->getJson('/api/access/company-users')->assertStatus(422);
    }

    public function test_owner_can_list_users_create_role_sync_permissions_and_audit_changes(): void
    {
        $ctx = $this->companyContext();

        $this->getJson('/api/access/company-users', $ctx['headers'])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $role = $this->postJson('/api/access/roles', [
            'name' => 'Billing Operator',
            'slug' => 'billing-operator',
            'permission_keys' => ['sales.invoices.view'],
        ], $ctx['headers'])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $ctx['company']->id)
            ->json('data');

        $permissionResponse = $this->putJson('/api/access/roles/'.$role['id'].'/permissions', [
            'permission_keys' => ['sales.invoices.view', 'sales.invoices.post'],
        ], $ctx['headers'])
            ->assertOk();
        $this->assertContains('sales.invoices.post', $permissionResponse->json('data.permission_keys'));

        $clone = $this->postJson('/api/access/roles/'.$role['id'].'/clone', [
            'name' => 'Billing Backup',
            'slug' => 'billing-backup',
        ], $ctx['headers'])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $ctx['company']->id)
            ->json('data');
        $this->patchJson('/api/access/roles/'.$clone['id'].'/deactivate', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->patchJson('/api/access/roles/'.$clone['id'].'/reactivate', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $target = $this->companyUser($ctx['company'], 'staff@example.test', 'viewer');
        $this->putJson('/api/access/users/'.$target->id.'/permissions', [
            'role_id' => $role['id'],
            'overrides' => [['permission_key' => 'sales.invoices.void', 'effect' => 'allow']],
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.company_user.role_id', $role['id']);

        $this->getJson('/api/access/audit', $ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['action' => 'access.role.created'])
            ->assertJsonFragment(['action' => 'access.permissions.update']);

        $this->getJson('/api/access/audit?role_id='.$role['id'], $ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['action' => 'access.role.permissions.synced']);

        $this->assertGreaterThanOrEqual(6, ActivityLog::query()->where('module', 'access')->count());
    }

    public function test_system_role_is_locked_and_custom_role_is_company_scoped(): void
    {
        $ctxA = $this->companyContext();
        $systemRole = Role::query()->where('slug', 'viewer')->firstOrFail();
        $this->patchJson('/api/access/roles/'.$systemRole->id, ['name' => 'Changed'], $ctxA['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SYSTEM_ROLE_READ_ONLY');

        $custom = $this->postJson('/api/access/roles', [
            'name' => 'Private Role',
            'slug' => 'private-role',
        ], $ctxA['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/access/roles/'.$custom['id'], ['is_active' => false], $ctxA['headers'])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $ctxB = $this->companyContext();
        $this->getJson('/api/access/roles/'.$custom['id'], $ctxB['headers'])->assertNotFound();
        $this->postJson('/api/access/roles', [
            'name' => 'Private Role',
            'slug' => 'private-role',
        ], $ctxB['headers'])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $ctxB['company']->id);
    }

    public function test_invitation_lifecycle_prevents_duplicate_active_invitation_and_is_audited(): void
    {
        $ctx = $this->companyContext();

        $invitation = $this->postJson('/api/access/invitations', [
            'email' => 'invitee@example.test',
            'role' => 'viewer',
        ], $ctx['headers'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data');

        $this->postJson('/api/access/invitations', [
            'email' => 'invitee@example.test',
            'role' => 'viewer',
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
            ->assertJsonPath('code', 'SELF_ACCESS_CHANGE_NOT_ALLOWED');

        $viewer = $this->companyUser($ctx['company'], 'readonly@example.test', 'viewer');
        Sanctum::actingAs($viewer->user);
        $this->getJson('/api/access/company-users', $ctx['headers'])->assertStatus(403);
    }

    private function companyContext(): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['status' => 'active']);
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
        Sanctum::actingAs($user);

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
        $path = database_path('tenants/test_access_'.$company->id.'_'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => basename($path),
            'database_path' => $path,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);
    }
}
