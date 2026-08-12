<?php

namespace Tests\Feature\Subscription;

use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\User;
use App\Shared\Subscription\SubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `SubscriptionService` — satu-satunya penulis `subscriptions` dan
 * `users.plan_id` (Fase 3, skema tier).
 */
class SubscriptionServiceTest extends TestCase
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

    private function plan(string $code): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    public function test_subscribe_computes_ends_at_for_monthly_and_yearly(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $start = Carbon::parse('2026-03-10');

        $monthly = $this->service()->subscribe($client, $this->plan('basic'), 'monthly', $start);
        $this->assertTrue($monthly->ends_at->isSameDay(Carbon::parse('2026-04-10')));

        $client2 = User::factory()->create(['status' => 'active']);
        $yearly = $this->service()->subscribe($client2, $this->plan('basic'), 'yearly', $start);
        $this->assertTrue($yearly->ends_at->isSameDay(Carbon::parse('2027-03-10')));
    }

    /**
     * 31 Januari + 1 bulan = 28/29 Februari, bukan melompat ke 3 Maret.
     */
    public function test_monthly_cycle_does_not_overflow_past_short_months(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $start = Carbon::parse('2026-01-31');

        $subscription = $this->service()->subscribe($client, $this->plan('basic'), 'monthly', $start);

        $this->assertTrue($subscription->ends_at->isSameDay(Carbon::parse('2026-02-28')));
    }

    public function test_price_is_locked_at_subscribe_time(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $plan = $this->plan('basic');
        $plan->forceFill(['monthly_price' => 100000])->save();

        $subscription = $this->service()->subscribe($client, $plan, 'monthly');
        $this->assertSame('100000.00', $subscription->price);

        // Kenaikan harga paket sesudahnya tidak menyentuh langganan yang
        // sedang berjalan.
        $plan->forceFill(['monthly_price' => 250000])->save();
        $this->assertSame('100000.00', $subscription->fresh()->price);
    }

    public function test_subscribe_sets_the_clients_plan_id(): void
    {
        $client = User::factory()->create(['status' => 'active', 'plan_id' => null]);
        $plan = $this->plan('pro');

        $this->service()->subscribe($client, $plan, 'monthly');

        $this->assertSame($plan->id, $client->fresh()->plan_id);
    }

    public function test_subscribe_refuses_a_second_active_subscription(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $this->service()->subscribe($client, $this->plan('basic'), 'monthly');

        $this->expectException(InvalidArgumentException::class);
        $this->service()->subscribe($client, $this->plan('pro'), 'monthly');
    }

    public function test_renew_creates_a_new_row_starting_exactly_at_previous_ends_at(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $start = Carbon::parse('2026-01-10');
        $first = $this->service()->subscribe($client, $this->plan('basic'), 'monthly', $start);

        $second = $this->service()->renew($client);

        $this->assertTrue($second->starts_at->isSameDay($first->ends_at));
        $this->assertTrue($second->ends_at->isSameDay(Carbon::parse('2026-03-10')));
        $this->assertSame(2, Subscription::query()->where('user_id', $client->id)->count());
    }

    public function test_renew_can_switch_plan_and_cycle(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $this->service()->subscribe($client, $this->plan('basic'), 'monthly');

        $enterprise = $this->plan('enterprise');
        $renewed = $this->service()->renew($client, $enterprise, 'yearly');

        $this->assertSame($enterprise->id, $renewed->plan_id);
        $this->assertSame('yearly', $renewed->billing_cycle);
        $this->assertSame($enterprise->id, $client->fresh()->plan_id);
    }

    public function test_renew_without_a_prior_subscription_is_refused(): void
    {
        $client = User::factory()->create(['status' => 'active']);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->renew($client);
    }

    public function test_state_at_the_four_boundaries(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $this->service()->subscribe($client, $this->plan('basic'), 'monthly', now()->subDays(29));
        // ends_at ~= now() + 1 day (30 hari dari mulai, cukup untuk uji "sehari sebelum").
        $service = $this->service();

        $this->assertSame(SubscriptionService::STATE_ACTIVE, $service->stateFor($client));

        $subscription = $service->currentFor($client);

        // Tepat di ends_at → tenggang, bukan aktif lagi.
        $this->travelTo($subscription->ends_at);
        $this->assertSame(SubscriptionService::STATE_GRACE, $service->stateFor($client));

        // Hari ke-7 tenggang → masih tenggang.
        $this->travelTo($subscription->ends_at->copy()->addDays(6)->endOfDay());
        $this->assertSame(SubscriptionService::STATE_GRACE, $service->stateFor($client));

        // Hari ke-8 → kedaluwarsa.
        $this->travelTo($subscription->ends_at->copy()->addDays(8));
        $this->assertSame(SubscriptionService::STATE_EXPIRED, $service->stateFor($client));
        $this->assertTrue($service->isLocked($client));

        $this->travelBack();
    }

    public function test_client_without_any_subscription_is_state_none_and_not_locked(): void
    {
        $client = User::factory()->create(['status' => 'active']);

        $this->assertSame(SubscriptionService::STATE_NONE, $this->service()->stateFor($client));
        $this->assertFalse($this->service()->isLocked($client));
        $this->assertNull($this->service()->daysRemaining($client));
    }

    public function test_cancel_locks_immediately_without_waiting_for_ends_at(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $this->service()->subscribe($client, $this->plan('basic'), 'monthly', now());

        $this->assertSame(SubscriptionService::STATE_ACTIVE, $this->service()->stateFor($client));

        $this->service()->cancel($client);

        $this->assertSame(SubscriptionService::STATE_CANCELLED, $this->service()->stateFor($client));
        $this->assertTrue($this->service()->isLocked($client));
    }

    public function test_client_with_subscription_history_cannot_be_deleted(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $this->service()->subscribe($client, $this->plan('basic'), 'monthly');

        $this->expectException(QueryException::class);
        $client->delete();
    }
}
