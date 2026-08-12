<?php

namespace App\Shared\Subscription;

use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Satu-satunya penulis `subscriptions` dan `users.plan_id`. Langganan
 * menempel di CLIENT (`users.plan_id`), bukan di perusahaan — client Pro
 * dengan tiga perusahaan membeli satu langganan yang mencakup ketiganya.
 *
 * `users.plan_id` diperlakukan sebagai turunan dengan satu penulis: hanya
 * kelas ini yang boleh mengubahnya, selalu bersamaan dengan membuat/mengubah
 * baris langganan. Kunci penuh (§2d) membuat ini aman untuk dipercaya tanpa
 * memeriksa tanggal di setiap request — client kedaluwarsa tidak bisa login
 * sama sekali, jadi tidak ada request yang membawa `plan_id` basi ke lapis
 * kuota atau lapis paket.
 */
class SubscriptionService
{
    /** Enum tertutup dua nilai — tidak ada kuartalan, tidak ada seumur hidup. */
    public const CYCLE_MONTHLY = 'monthly';

    public const CYCLE_YEARLY = 'yearly';

    private const VALID_CYCLES = [self::CYCLE_MONTHLY, self::CYCLE_YEARLY];

    /** Akses penuh selama tenggang; kunci penuh sesudahnya. */
    public const GRACE_DAYS = 7;

    public const STATE_NONE = 'none';

    public const STATE_ACTIVE = 'active';

    public const STATE_GRACE = 'grace';

    public const STATE_EXPIRED = 'expired';

    public const STATE_CANCELLED = 'cancelled';

    /**
     * Membuka langganan baru. Menolak kalau client sudah punya langganan
     * aktif/tenggang — hanya satu baris per client yang boleh berada di
     * salah satu dari dua keadaan itu. Mengunci harga dari paket SAAT INI ke
     * kolom `price`; kenaikan harga paket nanti tidak menyentuh langganan
     * yang sedang berjalan.
     */
    public function subscribe(User $client, Plan $plan, string $cycle, ?Carbon $startsAt = null): Subscription
    {
        $this->assertValidCycle($cycle);

        $state = $this->stateFor($client);
        if (in_array($state, [self::STATE_ACTIVE, self::STATE_GRACE], true)) {
            throw new InvalidArgumentException(
                'Client sudah punya langganan aktif atau dalam tenggang. Pakai renew() untuk memperpanjang.'
            );
        }

        $startsAt ??= now();
        $endsAt = $this->addCycle($startsAt, $cycle);

        $subscription = Subscription::query()->create([
            'user_id' => $client->id,
            'plan_id' => $plan->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'billing_cycle' => $cycle,
            'price' => $this->priceFor($plan, $cycle),
        ]);

        $client->forceFill(['plan_id' => $plan->id])->save();

        return $subscription;
    }

    /**
     * Baris baru yang mulai TEPAT di `ends_at` baris sebelumnya — bukan hari
     * ini. Riwayat penagihan terbentuk sendiri: setiap baris punya harga dan
     * periodenya sendiri. `$plan`/`$cycle` null berarti melanjutkan paket dan
     * siklus yang sama dengan langganan sebelumnya.
     */
    public function renew(User $client, ?Plan $plan = null, ?string $cycle = null): Subscription
    {
        $previous = $this->currentFor($client);

        if (! $previous) {
            throw new InvalidArgumentException(
                'Client belum pernah berlangganan. Pakai subscribe() untuk langganan pertama.'
            );
        }

        $cycle ??= $previous->billing_cycle;
        $this->assertValidCycle($cycle);
        $plan ??= $previous->plan;

        $startsAt = $previous->ends_at;
        $endsAt = $this->addCycle($startsAt, $cycle);

        $subscription = Subscription::query()->create([
            'user_id' => $client->id,
            'plan_id' => $plan->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'billing_cycle' => $cycle,
            'price' => $this->priceFor($plan, $cycle),
        ]);

        $client->forceFill(['plan_id' => $plan->id])->save();

        return $subscription;
    }

    /**
     * Menandai langganan berjalan sebagai dibatalkan. Baris TIDAK dihapus —
     * riwayatnya tetap ada, hanya `cancelled_at` yang terisi. Client langsung
     * masuk keadaan `cancelled` (terkunci), tanpa menunggu `ends_at`.
     */
    public function cancel(User $client): void
    {
        $current = $this->currentFor($client);

        if ($current && $current->cancelled_at === null) {
            $current->forceFill(['cancelled_at' => now()])->save();
        }
    }

    /**
     * Baris paling akhir milik client (berdasarkan `ends_at`), apa pun
     * keadaannya. Baris yang lebih lama sudah digantikan riwayat penagihan.
     */
    public function currentFor(User $client): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $client->id)
            ->orderByDesc('ends_at')
            ->first();
    }

    /**
     * `none` BUKAN `expired` — client yang belum pernah punya baris
     * `subscriptions` sama sekali (belum di-backfill sejak fase ini
     * diperkenalkan) tidak terkunci begitu saja. Hanya keadaan `expired` dan
     * `cancelled` yang mengunci login; lihat `isLocked()`.
     *
     * @return self::STATE_*
     */
    public function stateFor(User $client): string
    {
        $subscription = $this->currentFor($client);

        if (! $subscription) {
            return self::STATE_NONE;
        }

        if ($subscription->cancelled_at !== null) {
            return self::STATE_CANCELLED;
        }

        $now = now();

        if ($now->lt($subscription->ends_at)) {
            return self::STATE_ACTIVE;
        }

        if ($now->lt($subscription->ends_at->copy()->addDays(self::GRACE_DAYS))) {
            return self::STATE_GRACE;
        }

        return self::STATE_EXPIRED;
    }

    /**
     * Jalur pemulihan admin (§2d, §4d) — bukan perpanjangan penuh.
     * Menggeser `ends_at` langganan berjalan ke SEKARANG (dan membatalkan
     * `cancelled_at` kalau ada), sehingga client masuk keadaan `grace` selama
     * `GRACE_DAYS` berikutnya dengan akses penuh, tanpa mencatatnya sebagai
     * baris riwayat penagihan baru — status derivasinya tidak butuh itu.
     */
    public function unlock(User $client): Subscription
    {
        $current = $this->currentFor($client);

        if (! $current) {
            throw new InvalidArgumentException('Client belum pernah berlangganan; tidak ada yang bisa dibuka.');
        }

        $current->forceFill([
            'ends_at' => now(),
            'cancelled_at' => null,
        ])->save();

        return $current;
    }

    /** Dipakai titik login — lihat catatan kelas soal `plan_id` yang aman dipercaya. */
    public function isLocked(User $client): bool
    {
        return in_array($this->stateFor($client), [self::STATE_EXPIRED, self::STATE_CANCELLED], true);
    }

    /**
     * Sisa hari sampai `ends_at`. Negatif kalau sudah lewat (termasuk masa
     * tenggang). `null` kalau client belum pernah berlangganan.
     */
    public function daysRemaining(User $client): ?int
    {
        $subscription = $this->currentFor($client);

        if (! $subscription) {
            return null;
        }

        $today = CarbonImmutable::now()->startOfDay();
        $endsAt = CarbonImmutable::instance($subscription->ends_at)->startOfDay();

        return (int) $today->diffInDays($endsAt, false);
    }

    private function assertValidCycle(string $cycle): void
    {
        if (! in_array($cycle, self::VALID_CYCLES, true)) {
            throw new InvalidArgumentException("Siklus penagihan tidak valid: {$cycle}. Hanya 'monthly' atau 'yearly'.");
        }
    }

    /**
     * Varian NO-OVERFLOW wajib: langganan bulanan yang mulai 31 Januari
     * berakhir 28/29 Februari, bukan melompat ke 3 Maret.
     */
    private function addCycle(Carbon $from, string $cycle): Carbon
    {
        return $cycle === self::CYCLE_YEARLY
            ? $from->copy()->addYearNoOverflow()
            : $from->copy()->addMonthNoOverflow();
    }

    private function priceFor(Plan $plan, string $cycle): float
    {
        return (float) ($cycle === self::CYCLE_YEARLY ? $plan->yearly_price : $plan->monthly_price);
    }
}
