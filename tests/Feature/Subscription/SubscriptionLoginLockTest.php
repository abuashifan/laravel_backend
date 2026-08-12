<?php

namespace Tests\Feature\Subscription;

use App\Shared\Models\Plan;
use App\Shared\Models\User;
use App\Shared\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kunci penuh di titik login (Fase 3, skema tier §4c).
 */
class SubscriptionLoginLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTierPlans();
    }

    private function client(): User
    {
        return User::factory()->create([
            'status' => 'active',
            'password' => Hash::make('password123'),
        ]);
    }

    public function test_client_without_any_subscription_can_still_log_in(): void
    {
        $client = $this->client();

        $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => 'password123'])
            ->assertStatus(200);
    }

    public function test_client_with_active_subscription_can_log_in(): void
    {
        $client = $this->client();
        app(SubscriptionService::class)->subscribe($client, Plan::where('code', 'basic')->firstOrFail(), 'monthly');

        $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => 'password123'])
            ->assertStatus(200);
    }

    public function test_client_in_grace_period_can_still_log_in_with_full_access(): void
    {
        $client = $this->client();
        $subscription = app(SubscriptionService::class)->subscribe(
            $client, Plan::where('code', 'basic')->firstOrFail(), 'monthly', now()->subMonth()->subDays(3)
        );
        // ends_at sudah lewat beberapa hari, tapi masih dalam tenggang 7 hari.
        $this->assertTrue(now()->gt($subscription->ends_at));
        $this->assertTrue(now()->lt($subscription->ends_at->copy()->addDays(7)));

        $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => 'password123'])
            ->assertStatus(200);
    }

    public function test_client_past_grace_period_cannot_log_in(): void
    {
        $client = $this->client();
        app(SubscriptionService::class)->subscribe(
            $client, Plan::where('code', 'basic')->firstOrFail(), 'monthly', now()->subMonth()->subDays(10)
        );

        $res = $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => 'password123']);

        $res->assertStatus(403);
        $res->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED');
        $this->assertArrayHasKey('renewal_url', $res->json('meta'));
    }

    public function test_cancelled_client_cannot_log_in(): void
    {
        $client = $this->client();
        app(SubscriptionService::class)->subscribe($client, Plan::where('code', 'basic')->firstOrFail(), 'monthly');
        app(SubscriptionService::class)->cancel($client);

        $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => 'password123'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED');
    }

    public function test_admin_unlock_restores_login_for_seven_more_days(): void
    {
        $client = $this->client();
        app(SubscriptionService::class)->subscribe(
            $client, Plan::where('code', 'basic')->firstOrFail(), 'monthly', now()->subMonth()->subDays(10)
        );

        $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => 'password123'])
            ->assertStatus(403);

        app(SubscriptionService::class)->unlock($client);

        $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => 'password123'])
            ->assertStatus(200);
    }

    public function test_login_still_rejects_wrong_password_before_checking_subscription(): void
    {
        $client = $this->client();
        app(SubscriptionService::class)->subscribe(
            $client, Plan::where('code', 'basic')->firstOrFail(), 'monthly', now()->subMonth()->subDays(10)
        );

        $res = $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => 'wrong']);

        $res->assertStatus(422);
        $this->assertNotSame('SUBSCRIPTION_EXPIRED', $res->json('code'));
    }

    public function test_login_response_includes_subscription_summary_for_the_banner(): void
    {
        $client = $this->client();
        app(SubscriptionService::class)->subscribe($client, Plan::where('code', 'basic')->firstOrFail(), 'monthly');

        $res = $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => 'password123']);

        $res->assertStatus(200);
        $res->assertJsonPath('data.subscription.state', 'active');
        $this->assertIsInt($res->json('data.subscription.days_remaining'));
    }

    public function test_me_endpoint_returns_null_subscription_for_client_who_never_subscribed(): void
    {
        $client = $this->client();
        Sanctum::actingAs($client, ['*']);

        $this->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.subscription', null);
    }

    public function test_me_endpoint_reports_grace_state_for_the_banner(): void
    {
        $client = $this->client();
        app(SubscriptionService::class)->subscribe(
            $client, Plan::where('code', 'basic')->firstOrFail(), 'monthly', now()->subMonth()->subDays(3)
        );
        Sanctum::actingAs($client, ['*']);

        $this->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.subscription.state', 'grace');
    }
}
