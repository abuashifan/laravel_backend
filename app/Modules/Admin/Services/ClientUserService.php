<?php

namespace App\Modules\Admin\Services;

use App\Shared\Models\Subscription;
use App\Shared\Models\User;
use App\Shared\Subscription\CompanyQuotaService;
use App\Shared\Subscription\StorageQuotaService;
use App\Shared\Subscription\SubscriptionService;
use App\Shared\Subscription\UserQuotaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pengelolaan akun client oleh admin aplikasi.
 *
 * Sengaja hanya menyentuh database pusat: akun, status, dan paket. Tidak ada
 * satu pun jalur di sini yang membuka koneksi tenant, sehingga data keuangan
 * client tetap tidak terjangkau dari area admin.
 */
class ClientUserService
{
    public function __construct(
        private readonly CompanyQuotaService $quotaService,
        private readonly UserQuotaService $userQuotaService,
        private readonly SubscriptionService $subscriptionService,
        private readonly StorageQuotaService $storageQuotaService,
    ) {}

    /**
     * @param  array{search?:string|null, status?:string|null, plan_id?:int|null, page?:int, per_page?:int}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = max(1, min($perPage, 100));

        return $this->baseQuery($filters)
            ->orderByDesc('users.created_at')
            ->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    /**
     * @param  array{search?:string|null, status?:string|null, plan_id?:int|null}  $filters
     */
    private function baseQuery(array $filters): Builder
    {
        $query = User::query()
            ->with('plan')
            // Admin aplikasi tidak mengelola sesamanya lewat GUI: menaikkan
            // seseorang jadi admin adalah jalur eskalasi hak, dan itu hanya
            // lewat artisan di server.
            ->where('is_platform_admin', false)
            ->withCount(['companyUsers as owned_companies_count' => function ($q) {
                $q->where('role', 'owner')->where('status', 'active');
            }]);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')
                    ->orWhere('company_name', 'like', '%'.$search.'%');
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        if (! empty($filters['plan_id'])) {
            $query->where('plan_id', (int) $filters['plan_id']);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(User $user): array
    {
        $user->loadMissing('plan');

        $owned = $user->owned_companies_count ?? $this->quotaService->usedCount($user);
        $limit = $this->quotaService->limitFor($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'company_name' => $user->company_name,
            'job_title' => $user->job_title,
            'address' => $user->address,
            'notes' => $user->notes,
            'status' => $user->status,
            'plan' => $user->plan ? [
                'id' => $user->plan->id,
                'code' => $user->plan->code,
                'name' => $user->plan->name,
                'max_companies' => (int) $user->plan->max_companies,
                'is_custom' => $user->plan->isCustom(),
            ] : null,
            // NULL berarti mengikuti paket; angka berarti kuota khusus yang
            // ditetapkan owner aplikasi untuk client ini.
            'company_quota' => $user->company_quota !== null ? (int) $user->company_quota : null,
            'user_quota' => $user->user_quota !== null ? (int) $user->user_quota : null,
            // Add-on user: dibeli per client, berlaku di semua perusahaannya.
            'extra_users' => (int) $user->extra_users,
            'companies_used' => (int) $owned,
            'companies_limit' => $limit,
            'limit_source' => $this->quotaService->limitSourceFor($user),
            // Batas user berlaku per perusahaan, jadi yang ditampilkan adalah
            // angkanya saja — bukan "terpakai berapa" yang berbeda tiap
            // perusahaan. Dihitung service yang sama dengan penegakannya
            // supaya angka di admin tidak pernah berbeda dari kenyataan.
            'users_limit' => $this->userQuotaService->limitForOwner($user),
            // Menurunkan paket tidak mencabut perusahaan yang sudah ada, jadi
            // keadaan "melebihi jatah" itu sah dan harus terlihat di daftar.
            'over_quota' => (int) $owned > $limit,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
            'subscription' => $this->subscriptionPayload($user),
        ];
    }

    /**
     * Fase 3 — siklus langganan. `state` NULL berarti client belum pernah
     * berlangganan sama sekali (belum di-backfill); tidak sama dengan
     * `expired`. `history` diurutkan terbaru dulu untuk tab riwayat penagihan.
     *
     * @return array{state:string, ends_at:?string, days_remaining:?int, billing_cycle:?string, price:?string, plan_name:?string, history:list<array<string,mixed>>}
     */
    private function subscriptionPayload(User $user): array
    {
        $current = $this->subscriptionService->currentFor($user);

        $history = Subscription::query()
            ->with('plan:id,name,code')
            ->where('user_id', $user->id)
            ->orderByDesc('ends_at')
            ->get()
            ->map(fn (Subscription $s) => [
                'id' => $s->id,
                'plan_name' => $s->plan?->name,
                'billing_cycle' => $s->billing_cycle,
                'price' => $s->price,
                'starts_at' => $s->starts_at?->toISOString(),
                'ends_at' => $s->ends_at?->toISOString(),
                'cancelled_at' => $s->cancelled_at?->toISOString(),
            ])
            ->values()
            ->all();

        return [
            'state' => $this->subscriptionService->stateFor($user),
            'ends_at' => $current?->ends_at?->toISOString(),
            'days_remaining' => $this->subscriptionService->daysRemaining($user),
            'billing_cycle' => $current?->billing_cycle,
            'price' => $current?->price,
            'plan_name' => $current?->plan?->name,
            'history' => $history,
        ];
    }

    /**
     * Pemakaian penyimpanan tiap perusahaan milik client ini (Fase 4, skema
     * tier §"Area admin"). Angkanya seakurat pengukuran harian TERAKHIR
     * (`storage:measure`), bisa basi sampai satu hari — sama seperti yang
     * dipakai `StorageQuotaService` untuk menggerbangi unggahan impor.
     *
     * @return list<array<string, mixed>>
     */
    public function companiesWithStorage(User $client): array
    {
        return $client->ownedCompanies()
            ->with('tenantDatabase')
            ->get()
            ->map(function ($company) {
                $summary = $this->storageQuotaService->summaryFor($company);

                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'status' => $company->status,
                    ...$summary,
                    // 90% bukan batas keras — cuma penanda "mendekati" untuk
                    // area admin, supaya kelihatan sebelum client benar-benar
                    // mentok dan mulai gagal mengunggah.
                    'near_limit' => $summary['percent_used'] >= 90,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Client yang akan jatuh tempo ≤14 hari (H-14) atau sedang dalam masa
     * tenggang — daftar untuk dihubungi lewat WhatsApp (§4d).
     *
     * @return list<array<string, mixed>>
     */
    public function dueSoon(): array
    {
        $clientIds = Subscription::query()->distinct()->pluck('user_id');

        return User::query()
            ->whereIn('id', $clientIds)
            ->where('is_platform_admin', false)
            ->get()
            ->map(function (User $user) {
                $state = $this->subscriptionService->stateFor($user);
                $days = $this->subscriptionService->daysRemaining($user);

                return [$user, $state, $days];
            })
            ->filter(function (array $row) {
                [, $state, $days] = $row;

                return $state === SubscriptionService::STATE_GRACE
                    || ($state === SubscriptionService::STATE_ACTIVE && $days !== null && $days <= 14);
            })
            ->map(function (array $row) {
                [$user, $state, $days] = $row;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'state' => $state,
                    'days_remaining' => $days,
                ];
            })
            ->sortBy('days_remaining')
            ->values()
            ->all();
    }
}
