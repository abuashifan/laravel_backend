<?php

namespace App\Shared\Company;

use App\Shared\Audit\AuditLogService;
use App\Shared\Models\Company;
use App\Shared\Models\User;

/**
 * Penghapusan perusahaan oleh owner-nya dari halaman pilih perusahaan.
 *
 * **Satu-satunya yang diubah adalah `companies.deleted_at`.** Itu disengaja dan
 * penting: soft delete sudah cukup menutup semua pintu, karena setiap jalur
 * masuk melewati query builder Company yang kena SoftDeletingScope —
 * `EnsureCompanyAccess` (Company::find → 404), daftar picker, dan
 * `CompanyQuotaService::usedCount()` yang otomatis membebaskan slot kuota.
 *
 * Versi awal service ini juga menimpa `company_users.status` jadi `removed` dan
 * `tenant_databases.status` jadi `deleted`. Itu dibuang karena merusak
 * pemulihan: status asli tiap staf (mis. yang memang sengaja dinonaktifkan
 * owner) ikut tertimpa dan tidak bisa dikembalikan seperti semula. Data di
 * dalam tenant — produk nonaktif, transaksi void — memang tidak pernah
 * tersentuh karena file tenant tidak dibuka sama sekali.
 *
 * Penghapusan permanennya ada di `CompanyPurgeService`, dijalankan
 * `companies:sweep-deleted` setelah masa pemulihan habis.
 */
class CompanyDeletionService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function delete(Company $company, User $initiator): void
    {
        $company->delete();

        $this->auditLogService->logSuccess([
            'event' => 'companies.delete',
            'action' => 'companies.delete',
            'module' => 'companies',
            'message' => 'Company deleted by owner.',
            'record_type' => Company::class,
            'record_id' => $company->id,
            'record_number' => $company->code,
            'metadata' => [
                'name' => $company->name,
                'slug' => $company->slug,
                'purge_after' => $company->deleted_at
                    ?->copy()
                    ->addDays((int) config('companies.deletion_retention_days', 30))
                    ->toDateTimeString(),
            ],
            'user_id' => $initiator->id,
        ], tenant: false);
    }
}
