<?php

namespace Tests\Feature\Admin;

use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\User;
use App\Shared\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Area admin — tab Langganan (Fase 3, skema tier §4d).
 */
class AdminSubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTierPlans();
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'status' => 'active',
            'is_platform_admin' => true,
            'password' => Hash::make('password123'),
        ]);
    }

    private function actingAsAdmin(User $admin): self
    {
        $token = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ])->assertStatus(200)->json('data.token');

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_admin_can_subscribe_a_client_for_the_first_time(): void
    {
        $admin = $this->platformAdmin();
        $client = User::factory()->create(['status' => 'active']);
        $plan = Plan::where('code', 'pro')->firstOrFail();

        $this->actingAsAdmin($admin)
            ->postJson("/api/admin/clients/{$client->id}/subscribe", [
                'plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.subscription.state', 'active')
            ->assertJsonPath('data.subscription.billing_cycle', 'monthly')
            ->assertJsonPath('data.plan.code', 'pro');
    }

    public function test_admin_cannot_subscribe_a_client_who_already_has_an_active_subscription(): void
    {
        $admin = $this->platformAdmin();
        $client = User::factory()->create(['status' => 'active']);
        app(SubscriptionService::class)->subscribe($client, Plan::where('code', 'basic')->firstOrFail(), 'monthly');

        $this->actingAsAdmin($admin)
            ->postJson("/api/admin/clients/{$client->id}/subscribe", [
                'plan_id' => Plan::where('code', 'pro')->value('id'),
                'billing_cycle' => 'monthly',
            ])
            ->assertStatus(422);
    }

    public function test_admin_can_renew_a_client(): void
    {
        $admin = $this->platformAdmin();
        $client = User::factory()->create(['status' => 'active']);
        app(SubscriptionService::class)->subscribe($client, Plan::where('code', 'basic')->firstOrFail(), 'monthly');

        $this->actingAsAdmin($admin)
            ->postJson("/api/admin/clients/{$client->id}/renew")
            ->assertStatus(200)
            ->assertJsonPath('data.subscription.state', 'active');

        $this->assertCount(2, Subscription::where('user_id', $client->id)->get());
    }

    public function test_admin_renew_can_switch_plan(): void
    {
        $admin = $this->platformAdmin();
        $client = User::factory()->create(['status' => 'active']);
        app(SubscriptionService::class)->subscribe($client, Plan::where('code', 'basic')->firstOrFail(), 'monthly');
        $enterprise = Plan::where('code', 'enterprise')->firstOrFail();

        $this->actingAsAdmin($admin)
            ->postJson("/api/admin/clients/{$client->id}/renew", ['plan_id' => $enterprise->id])
            ->assertStatus(200)
            ->assertJsonPath('data.plan.code', 'enterprise');
    }

    public function test_admin_can_unlock_an_expired_client(): void
    {
        $admin = $this->platformAdmin();
        $client = User::factory()->create(['status' => 'active']);
        app(SubscriptionService::class)->subscribe(
            $client, Plan::where('code', 'basic')->firstOrFail(), 'monthly', now()->subMonth()->subDays(10)
        );
        $this->assertSame(SubscriptionService::STATE_EXPIRED, app(SubscriptionService::class)->stateFor($client));

        $this->actingAsAdmin($admin)
            ->postJson("/api/admin/clients/{$client->id}/unlock")
            ->assertStatus(200)
            ->assertJsonPath('data.subscription.state', 'grace');
    }

    public function test_due_soon_lists_clients_within_fourteen_days_and_in_grace(): void
    {
        $admin = $this->platformAdmin();
        $service = app(SubscriptionService::class);

        $dueSoon = User::factory()->create(['status' => 'active', 'name' => 'Client Due Soon']);
        $service->subscribe($dueSoon, Plan::where('code', 'basic')->firstOrFail(), 'monthly', now()->subMonth()->addDays(10));

        $inGrace = User::factory()->create(['status' => 'active', 'name' => 'Client In Grace']);
        $service->subscribe($inGrace, Plan::where('code', 'basic')->firstOrFail(), 'monthly', now()->subMonth()->subDays(2));

        $farAway = User::factory()->create(['status' => 'active', 'name' => 'Client Far Away']);
        $service->subscribe($farAway, Plan::where('code', 'basic')->firstOrFail(), 'monthly');

        $res = $this->actingAsAdmin($admin)->getJson('/api/admin/clients/due-soon')->assertStatus(200);

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($dueSoon->id, $ids);
        $this->assertContains($inGrace->id, $ids);
        $this->assertNotContains($farAway->id, $ids);
    }

    public function test_admin_can_see_storage_usage_per_company_of_a_client(): void
    {
        $admin = $this->platformAdmin();
        $client = $this->clientOn('basic');
        $company = $this->companyOwnedBy($client);
        $company->tenantDatabase()->update([
            'size_bytes' => (int) (1024 * 1024 * 1024 * 0.95),
            'measured_at' => now(),
        ]);

        $res = $this->actingAsAdmin($admin)
            ->getJson("/api/admin/clients/{$client->id}/storage")
            ->assertStatus(200);

        $res->assertJsonPath('data.0.id', $company->id);
        $res->assertJsonPath('data.0.near_limit', true);
        $this->assertGreaterThanOrEqual(90.0, $res->json('data.0.percent_used'));
    }

    public function test_unmeasured_company_shows_zero_usage_not_near_limit(): void
    {
        $admin = $this->platformAdmin();
        $client = $this->clientOn('pro');
        $company = $this->companyOwnedBy($client);

        $res = $this->actingAsAdmin($admin)
            ->getJson("/api/admin/clients/{$client->id}/storage")
            ->assertStatus(200);

        $res->assertJsonPath('data.0.used_bytes', 0);
        $res->assertJsonPath('data.0.near_limit', false);
        $res->assertJsonPath('data.0.measured_at', null);
    }

    public function test_client_endpoints_require_platform_admin(): void
    {
        $client = User::factory()->create(['status' => 'active']);

        $this->postJson("/api/admin/clients/{$client->id}/renew")->assertStatus(401);
        $this->getJson('/api/admin/clients/due-soon')->assertStatus(401);
    }
}
