<?php

namespace App\Shared\Company;

use App\Shared\Audit\AuditLogService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\User;
use App\Shared\Subscription\CompanyQuotaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pemulihan perusahaan yang dihapus — hanya oleh super admin (lihat
 * `Modules/Admin/Routes/api.php`), bukan oleh client sendiri.
 *
 * Kebalikan persis dari `CompanyDeletionService`: yang dihapus cuma
 * `deleted_at`, jadi seluruh keadaan sebelum penghapusan kembali apa adanya —
 * status tiap staf, produk nonaktif, dan transaksi void tetap sebagaimana
 * adanya karena memang tidak pernah disentuh.
 */
class CompanyRestoreService
{
    public function __construct(
        private readonly CompanyQuotaService $quotaService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function retentionDays(): int
    {
        return (int) config('companies.deletion_retention_days', 30);
    }

    /**
     * Batas akhir sebuah perusahaan masih bisa dipulihkan. `null` bila
     * perusahaannya tidak sedang terhapus.
     */
    public function purgeAfterFor(Company $company): ?Carbon
    {
        return $company->deleted_at?->copy()->addDays($this->retentionDays());
    }

    /**
     * Owner yang jatah kuotanya terpakai oleh perusahaan ini. Biasanya satu,
     * tapi kuota dihitung per user — kalau ada lebih dari satu owner, slot
     * terpakai di semuanya, jadi semuanya harus diperiksa.
     *
     * @return Collection<int, User>
     */
    public function ownersOf(Company $company)
    {
        $ownerIds = CompanyUser::query()
            ->where('company_id', $company->id)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->pluck('user_id');

        return User::query()->whereIn('id', $ownerIds)->get();
    }

    /**
     * Alasan perusahaan ini belum bisa dipulihkan, atau `null` bila aman.
     * Dipakai controller untuk menandai baris di daftar admin sebelum super
     * admin menekan tombolnya — bukan hanya menolak setelah ditekan.
     */
    public function blockerFor(Company $company): ?array
    {
        if (! $company->trashed()) {
            return ['code' => 'COMPANY_NOT_DELETED', 'message' => 'Perusahaan ini tidak sedang terhapus.'];
        }

        if ($this->purgeAfterFor($company)?->isPast()) {
            return [
                'code' => 'COMPANY_RESTORE_WINDOW_EXPIRED',
                'message' => sprintf(
                    'Masa pemulihan %d hari sudah lewat. Perusahaan ini menunggu penghapusan permanen.',
                    $this->retentionDays(),
                ),
            ];
        }

        foreach ($this->ownersOf($company) as $owner) {
            if (! $this->quotaService->canCreate($owner)) {
                $summary = $this->quotaService->summaryFor($owner);

                return [
                    'code' => 'COMPANY_RESTORE_QUOTA_EXCEEDED',
                    'message' => sprintf(
                        'Kuota %s sudah penuh (%d/%d). Hapus dulu salah satu perusahaan aktifnya untuk membebaskan slot.',
                        $owner->email,
                        $summary['used'],
                        $summary['limit'],
                    ),
                ];
            }
        }

        return null;
    }

    public function restore(Company $company, User $initiator): void
    {
        $blocker = $this->blockerFor($company);

        if ($blocker !== null) {
            throw ApiException::make($blocker['code'], $blocker['message'], 422);
        }

        $company->restore();

        $this->auditLogService->logSuccess([
            'event' => 'companies.restore',
            'action' => 'companies.restore',
            'module' => 'companies',
            'message' => 'Company restored by platform admin.',
            'record_type' => Company::class,
            'record_id' => $company->id,
            'record_number' => $company->code,
            'metadata' => ['name' => $company->name, 'slug' => $company->slug],
            'user_id' => $initiator->id,
        ], tenant: false);
    }
}
