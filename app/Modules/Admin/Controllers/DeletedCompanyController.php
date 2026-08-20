<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiResponse;
use App\Shared\Company\CompanyPurgeService;
use App\Shared\Company\CompanyRestoreService;
use App\Shared\Models\Company;
use App\Shared\Subscription\CompanyQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perusahaan terhapus, dilihat dan dipulihkan oleh super admin aplikasi.
 *
 * Sengaja hidup di modul Admin, bukan Companies: pemulihan bukan wewenang
 * client. Owner hanya bisa menghapus; mengembalikannya butuh orang yang bisa
 * menilai kuota dan alasan penghapusan.
 *
 * Seperti route admin lainnya, tidak ada `company.access` di sini — endpoint
 * ini hanya menyentuh tabel pusat dan tidak pernah membuka koneksi tenant.
 */
class DeletedCompanyController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CompanyRestoreService $restoreService,
        private readonly CompanyQuotaService $quotaService,
    ) {}

    public function index(): JsonResponse
    {
        $companies = Company::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (Company $company) => $this->payload($company))
            ->values();

        return $this->successResponse(
            $companies,
            'Deleted companies retrieved successfully',
            200,
            ['retention_days' => $this->restoreService->retentionDays()]
        );
    }

    public function restore(int $companyId, Request $request): JsonResponse
    {
        $company = $this->deletedCompany($companyId);

        // Pelanggaran kuota / masa pemulihan dilempar sebagai ApiException
        // dengan kode spesifik oleh service, jadi tidak perlu ditangkap di sini.
        $this->restoreService->restore($company, $request->user());

        return $this->successResponse(
            $this->payload($company->refresh()),
            'Perusahaan berhasil dipulihkan.'
        );
    }

    /**
     * Hapus permanen sebelum masa pemulihan habis — jalan keluar saat kuota
     * client penuh dan slotnya perlu dibebaskan. Nama perusahaan harus
     * diketik ulang persis, sama seperti penghapusan oleh owner.
     */
    public function purge(int $companyId, Request $request, CompanyPurgeService $purgeService): JsonResponse
    {
        $company = $this->deletedCompany($companyId);

        $data = $request->validate([
            'confirm_name' => ['required', 'string'],
        ]);

        if ($company->name !== trim($data['confirm_name'])) {
            return $this->validationErrorResponse(
                ['confirm_name' => ['Nama perusahaan tidak cocok.']],
                'Konfirmasi nama perusahaan tidak cocok.'
            );
        }

        $purgeService->purge($company, $request->user());

        return $this->successResponse(null, 'Perusahaan dihapus permanen.');
    }

    private function deletedCompany(int $companyId): Company
    {
        return Company::onlyTrashed()->findOrFail($companyId);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Company $company): array
    {
        $purgeAfter = $this->restoreService->purgeAfterFor($company);

        $owners = $this->restoreService->ownersOf($company)
            ->map(function ($owner) {
                $summary = $this->quotaService->summaryFor($owner);

                return [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'quota_used' => $summary['used'],
                    'quota_limit' => $summary['limit'],
                    'quota_available' => $summary['can_create'],
                ];
            })
            ->values()
            ->all();

        // Perusahaan yang sudah lewat tenggat tetap ditampilkan (sweep harian
        // mungkin belum jalan) tapi ditandai, supaya admin tahu tombol
        // pulihkannya memang mati — bukan sedang rusak.
        $blocker = $company->trashed() ? $this->restoreService->blockerFor($company) : null;

        return [
            'id' => $company->id,
            'name' => $company->name,
            'code' => $company->code,
            'slug' => $company->slug,
            'deleted_at' => $company->deleted_at?->toIso8601String(),
            'purge_after' => $purgeAfter?->toIso8601String(),
            'days_remaining' => $purgeAfter ? max(0, (int) now()->startOfDay()->diffInDays($purgeAfter->copy()->startOfDay(), false)) : null,
            'is_expired' => (bool) $purgeAfter?->isPast(),
            'owners' => $owners,
            'can_restore' => $blocker === null,
            'restore_blocker' => $blocker,
        ];
    }
}
