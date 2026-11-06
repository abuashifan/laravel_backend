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
     * Produk yang ditanggung langganan (Fase 0 POS). Semua metode menerima
     * `$product`; nilai bawaan `accounting` menjaga pemanggil lama tetap
     * berarti langganan Finlite Accounting. `users.plan_id` hanya mencerminkan
     * paket akuntansi -- langganan produk lain tidak menyentuhnya.
     */
    public const PRODUCT_ACCOUNTING = 'accounting';

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

        $product = $plan->productKey();
        $state = $this->stateFor($client, $product);
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
            'product' => $product,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'billing_cycle' => $cycle,
            'price' => $this->priceFor($plan, $cycle),
        ]);

        $this->syncUserPlan($client, $plan);

        return $subscription;
    }

    /**
     * Baris baru yang mulai TEPAT di `ends_at` baris sebelumnya — bukan hari
     * ini. Riwayat penagihan terbentuk sendiri: setiap baris punya harga dan
     * periodenya sendiri. `$plan`/`$cycle` null berarti melanjutkan paket dan
     * siklus yang sama dengan langganan sebelumnya.
     */
    public function renew(User $client, ?Plan $plan = null, ?string $cycle = null, string $product = self::PRODUCT_ACCOUNTING): Subscription
    {
        $product = $plan?->productKey() ?? $product;
        $previous = $this->currentFor($client, $product);

        if (! $previous) {
            throw new InvalidArgumentException(
                'Client belum pernah berlangganan. Pakai subscribe() untuk langganan pertama.'
            );
        }

        $cycle ??= $previous->billing_cycle;
        $this->assertValidCycle($cycle);
        $plan ??= $previous->plan;
        if ($plan->productKey() !== $product) {
            throw new InvalidArgumentException('Paket baru harus untuk produk yang sama dengan langganan sebelumnya.');
        }

        $startsAt = $previous->ends_at;
        $endsAt = $this->addCycle($startsAt, $cycle);

        $subscription = Subscription::query()->create([
            'user_id' => $client->id,
            'plan_id' => $plan->id,
            'product' => $product,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'billing_cycle' => $cycle,
            'price' => $this->priceFor($plan, $cycle),
        ]);

        $this->syncUserPlan($client, $plan);

        return $subscription;
    }

    /**
     * Menandai langganan berjalan sebagai dibatalkan. Baris TIDAK dihapus —
     * riwayatnya tetap ada, hanya `cancelled_at` yang terisi. Client langsung
     * masuk keadaan `cancelled` (terkunci), tanpa menunggu `ends_at`.
     */
    public function cancel(User $client, string $product = self::PRODUCT_ACCOUNTING): void
    {
        $current = $this->currentFor($client, $product);

        if ($current && $current->cancelled_at === null) {
            $current->forceFill(['cancelled_at' => now()])->save();
        }
    }

    /**
     * Baris paling akhir milik client (berdasarkan `ends_at`), apa pun
     * keadaannya. Baris yang lebih lama sudah digantikan riwayat penagihan.
     */
    public function currentFor(User $client, string $product = self::PRODUCT_ACCOUNTING): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $client->id)
            ->where('product', $product)
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
    public function stateFor(User $client, string $product = self::PRODUCT_ACCOUNTING): string
    {
        $subscription = $this->currentFor($client, $product);

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
    public function unlock(User $client, string $product = self::PRODUCT_ACCOUNTING): Subscription
    {
        $current = $this->currentFor($client, $product);

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
    public function isLocked(User $client, string $product = self::PRODUCT_ACCOUNTING): bool
    {
        return in_array($this->stateFor($client, $product), [self::STATE_EXPIRED, self::STATE_CANCELLED], true);
    }

    /**
     * Masa coba gratis untuk produk paket ini (dipakai pendaftaran mandiri
     * Finlite Kasir). Hanya sekali per produk: client yang pernah punya
     * langganan produk itu tidak mendapat trial lagi (null).
     */
    public function startTrial(User $client, Plan $plan, int $days): ?Subscription
    {
        $product = $plan->productKey();
        if ($this->currentFor($client, $product)) {
            return null;
        }

        $startsAt = now();
        $subscription = Subscription::query()->create([
            'user_id' => $client->id,
            'plan_id' => $plan->id,
            'product' => $product,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addDays($days),
            'billing_cycle' => self::CYCLE_MONTHLY,
            'price' => 0,
            'metadata' => ['trial' => true],
        ]);

        $this->syncUserPlan($client, $plan);

        return $subscription;
    }

    /**
     * Ringkasan langganan satu produk untuk spanduk aplikasi.
     *
     * `limits` = batas paket yang sedang berlaku (null = tanpa batas).
     *
     * @return array{state:string, ends_at:string|null, days_remaining:int|null, is_trial:bool, plan_name:string|null, plan_code:string|null, billing_cycle:string|null, limits:array{users:int|null, stores:int|null, products:int|null}|null}
     */
    public function summaryFor(User $client, string $product): array
    {
        $current = $this->currentFor($client, $product);
        $plan = $current?->plan;

        return [
            'state' => $this->stateFor($client, $product),
            'ends_at' => $current?->ends_at?->toISOString(),
            'days_remaining' => $current ? (int) floor(now()->diffInDays($current->ends_at, false)) : null,
            'is_trial' => (bool) ($current?->metadata['trial'] ?? false),
            'plan_name' => $plan?->name,
            'plan_code' => $plan?->code,
            'billing_cycle' => $current?->billing_cycle,
            'limits' => $plan ? [
                'users' => $plan->max_users !== null ? (int) $plan->max_users : null,
                'stores' => $plan->max_companies !== null ? (int) $plan->max_companies : null,
                'products' => $plan->max_products,
            ] : null,
        ];
    }

    /** Langganan produk ini sedang aktif atau dalam masa tenggang. */
    public function hasAccess(User $client, string $product): bool
    {
        return in_array($this->stateFor($client, $product), [self::STATE_ACTIVE, self::STATE_GRACE], true);
    }

    /** Paket yang sedang berlaku untuk satu produk (dari langganan terakhir). */
    public function planFor(User $client, string $product): ?Plan
    {
        if ($product === self::PRODUCT_ACCOUNTING) {
            return $client->plan;
        }

        return $this->currentFor($client, $product)?->plan;
    }

    /**
     * Sisa hari sampai `ends_at`. Negatif kalau sudah lewat (termasuk masa
     * tenggang). `null` kalau client belum pernah berlangganan.
     */
    public function daysRemaining(User $client, string $product = self::PRODUCT_ACCOUNTING): ?int
    {
        $subscription = $this->currentFor($client, $product);

        if (! $subscription) {
            return null;
        }

        $today = CarbonImmutable::now()->startOfDay();
        $endsAt = CarbonImmutable::instance($subscription->ends_at)->startOfDay();

        return (int) $today->diffInDays($endsAt, false);
    }

    /** `users.plan_id` = paket akuntansi saja (dibaca kuota & gerbang fitur akuntansi). */
    private function syncUserPlan(User $client, Plan $plan): void
    {
        if ($plan->productKey() === self::PRODUCT_ACCOUNTING) {
            $client->forceFill(['plan_id' => $plan->id])->save();
        }
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
