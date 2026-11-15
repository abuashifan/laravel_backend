<?php

namespace Tests\Feature\Subscription;

use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\User;
use App\Shared\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kasir (`summaryFor`) dan Accounting (`daysRemaining`) harus menampilkan sisa hari
 * yang sama untuk tanggal berakhir yang sama: selisih tanggal kalender.
 */
class SubscriptionDaysRemainingTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_and_days_remaining_agree_for_any_time_of_day(): void
    {
        $service = app(SubscriptionService::class);

        // Berakhir besok lusa, pada jam yang berbeda-beda: 1,x hari menurut jam, 2 menurut kalender.
        foreach (['00:05', '10:30', '23:59'] as $time) {
            $this->travelTo(now()->setTime(10, 30));
            $user = User::factory()->create(['status' => 'active']);
            Subscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => Plan::query()->where('code', Plan::POS_CODE)->value('id'),
                'product' => 'pos',
                'starts_at' => now()->subDays(28),
                'ends_at' => now()->addDays(2)->setTimeFromTimeString($time),
                'billing_cycle' => 'monthly',
                'price' => 0,
            ]);

            $summary = $service->summaryFor($user, 'pos');
            $this->assertSame(2, $summary['days_remaining'], "jam {$time}");
            $this->assertSame($service->daysRemaining($user, 'pos'), $summary['days_remaining'], "jam {$time}");
        }
    }

    public function test_no_subscription_has_no_days_remaining(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->assertNull(app(SubscriptionService::class)->summaryFor($user, 'pos')['days_remaining']);
    }
}
