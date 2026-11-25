<?php

namespace Tests\Feature\Workspace;

use App\Shared\Models\CompanyInvitation;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkspaceFoundationTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDirectory = storage_path('framework/testing/workspace-'.uniqid('', true));
        File::ensureDirectoryExists($this->tenantDirectory);
        config(['tenant.database_path' => $this->tenantDirectory, 'tenant.driver' => 'sqlite']);
    }

    protected function tearDown(): void
    {
        DB::disconnect('workspace');
        if ($this->tenantDirectory !== '') {
            File::deleteDirectory($this->tenantDirectory);
        }
        parent::tearDown();
    }

    public function test_workspace_login_and_product_are_independent_of_accounting(): void
    {
        $owner = $this->owner();
        $accounting = $this->activeSubscriptionFor($owner);
        $accounting->update(['starts_at' => now()->subMonths(4), 'ends_at' => now()->subMonths(2)]);
        $credentials = ['email' => $owner->email, 'password' => 'phase-one-password'];

        $this->postJson('/api/auth/login', $credentials)->assertForbidden();
        $this->postJson('/api/auth/login', $credentials + ['app' => 'workspace'])->assertOk()->assertJsonStructure(['data' => ['token']]);

        Sanctum::actingAs($owner, ['client']);
        $company = $this->workspaceCompany();
        $headers = ['X-Company-ID' => (string) $company];
        $this->assertSame(['workspace'], TenantDatabase::allProducts()->where('company_id', $company)->pluck('product')->all());
        $this->getJson('/api/workspace/bootstrap', $headers)->assertOk()
            ->assertJsonPath('data.read_only', false)->assertJsonPath('data.budget_source', 'accounting');
        $this->postJson('/api/workspace/units', ['code' => 'Sek-A', 'name' => 'Sekolah A', 'is_active' => true], $headers)
            ->assertCreated()->assertJsonPath('data.code', 'SEK-A');
        $this->postJson('/api/workspace/units', ['code' => 'sek-a', 'name' => 'Duplikat', 'is_active' => true], $headers)->assertUnprocessable();
    }

    public function test_invitation_preserves_accounting_permissions_and_unit_scope(): void
    {
        Mail::fake();
        $owner = $this->owner();
        Sanctum::actingAs($owner, ['client']);
        $company = $this->workspaceCompany();
        $headers = ['X-Company-ID' => (string) $company];
        $unit = $this->postJson('/api/workspace/units', ['code' => 'A', 'name' => 'Unit A', 'is_active' => true], $headers)->assertCreated()->json('data.id');
        $this->postJson('/api/workspace/units', ['code' => 'B', 'name' => 'Unit B', 'is_active' => true], $headers)->assertCreated();

        $staff = User::factory()->create(['status' => 'active']);
        $membership = CompanyUser::query()->create(['company_id' => $company, 'user_id' => $staff->id, 'status' => 'active', 'role' => 'viewer', 'full_access' => false]);
        $this->assignTestPermissions($membership, ['journal.view']);

        $this->postJson('/api/workspace/invitations', ['email' => $staff->email], $headers)
            ->assertCreated()->assertJsonPath('data.mail_sent', true);
        $invitation = CompanyInvitation::query()->where('company_id', $company)->firstOrFail();
        Sanctum::actingAs($staff, ['client']);
        $this->postJson('/api/invitations/'.$invitation->token.'/accept')->assertOk();
        $this->assertEqualsCanonicalizing(['journal.view', 'workspace.units.view'], $membership->assignedPermissions()->pluck('key')->all());
        $this->assertFalse($membership->fresh()->full_access);
        $this->getJson('/api/workspace/units', $headers)->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson('/api/workspace/units/'.$unit.'/members', $headers)->assertForbidden();

        Sanctum::actingAs($owner, ['client']);
        $path = '/api/workspace/units/'.$unit.'/members/'.$membership->id;
        $this->putJson($path, [], $headers)->assertOk();
        $this->putJson($path, [], $headers)->assertOk();
        $this->getJson('/api/workspace/units/'.$unit.'/members', $headers)->assertOk()->assertJsonCount(1, 'data');
        Sanctum::actingAs($staff, ['client']);
        $this->getJson('/api/workspace/units', $headers)->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.code', 'A');
        $this->putJson('/api/workspace/units/'.$unit, ['code' => 'A', 'name' => 'Diubah', 'is_active' => true], $headers)->assertForbidden();
        $this->getJson('/api/workspace/units', ['X-Company-ID' => '999999'])->assertNotFound();
    }

    private function workspaceCompany(): int
    {
        return (int) $this->postJson('/api/companies', ['name' => 'Yayasan Contoh', 'product' => 'workspace'])
            ->assertCreated()->assertJsonPath('data.products', ['workspace'])->json('data.id');
    }

    private function owner(): User
    {
        $user = User::factory()->create(['status' => 'active', 'password' => 'phase-one-password']);
        Subscription::query()->create([
            'user_id' => $user->id, 'plan_id' => Plan::query()->where('code', 'workspace_pilot')->firstOrFail()->id,
            'product' => 'workspace', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
            'billing_cycle' => 'monthly', 'price' => 0,
        ]);

        return $user;
    }
}
