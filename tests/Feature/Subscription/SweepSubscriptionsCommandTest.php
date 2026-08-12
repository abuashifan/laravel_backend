<?php

namespace Tests\Feature\Subscription;

use App\Shared\Models\Plan;
use App\Shared\Models\User;
use App\Shared\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan subscriptions:sweep` — dua tugas yang tidak memengaruhi
 * kebenaran gerbang (Fase 3, skema tier §4e).
 */
class SweepSubscriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTierPlans();
    }

    private function service(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    public function test_dry_run_does_not_revoke_tokens(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $this->service()->subscribe($client, Plan::where('code', 'basic')->firstOrFail(), 'monthly', now()->subMonth()->subDays(10));
        $client->createToken('api-token');

        $this->assertSame(1, $client->tokens()->count());

        $this->artisan('subscriptions:sweep --dry-run')->assertSuccessful();

        $this->assertSame(1, $client->tokens()->count());
    }

    public function test_sweep_revokes_tokens_of_expired_clients(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $this->service()->subscribe($client, Plan::where('code', 'basic')->firstOrFail(), 'monthly', now()->subMonth()->subDays(10));
        $client->createToken('api-token');
        $client->createToken('api-token-2');

        $this->artisan('subscriptions:sweep')->assertSuccessful();

        $this->assertSame(0, $client->tokens()->count());
    }

    public function test_sweep_revokes_tokens_of_cancelled_clients(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $this->service()->subscribe($client, Plan::where('code', 'basic')->firstOrFail(), 'monthly');
        $this->service()->cancel($client);
        $client->createToken('api-token');

        $this->artisan('subscriptions:sweep')->assertSuccessful();

        $this->assertSame(0, $client->tokens()->count());
    }

    public function test_sweep_does_not_touch_tokens_of_active_or_grace_clients(): void
    {
        $active = User::factory()->create(['status' => 'active']);
        $this->service()->subscribe($active, Plan::where('code', 'basic')->firstOrFail(), 'monthly');
        $active->createToken('api-token');

        $grace = User::factory()->create(['status' => 'active']);
        $this->service()->subscribe($grace, Plan::where('code', 'basic')->firstOrFail(), 'monthly', now()->subMonth()->subDays(3));
        $grace->createToken('api-token');

        $this->artisan('subscriptions:sweep')->assertSuccessful();

        $this->assertSame(1, $active->tokens()->count());
        $this->assertSame(1, $grace->tokens()->count());
    }

    public function test_sweep_with_no_subscriptions_at_all_succeeds(): void
    {
        $this->artisan('subscriptions:sweep')->assertSuccessful();
    }
}
