<?php

namespace App\Modules\Admin\Services;

use App\Shared\Models\User;
use App\Shared\Subscription\CompanyQuotaService;
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
    public function __construct(private readonly CompanyQuotaService $quotaService) {}

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
                    ->orWhere('email', 'like', '%'.$search.'%');
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
            'status' => $user->status,
            'plan' => $user->plan ? [
                'id' => $user->plan->id,
                'code' => $user->plan->code,
                'name' => $user->plan->name,
                'max_companies' => (int) $user->plan->max_companies,
            ] : null,
            'companies_used' => (int) $owned,
            'companies_limit' => $limit,
            // Menurunkan paket tidak mencabut perusahaan yang sudah ada, jadi
            // keadaan "melebihi jatah" itu sah dan harus terlihat di daftar.
            'over_quota' => (int) $owned > $limit,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
