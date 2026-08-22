<?php

namespace App\Shared\Company;

use App\Shared\Audit\AuditLogService;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Penghapusan permanen — titik tanpa kembali.
 *
 * Dijalankan `companies:sweep-deleted` untuk perusahaan yang masa pemulihannya
 * habis, dan bisa dipanggil super admin lebih awal saat butuh membebaskan slot
 * kuota client (lihat `DeletedCompanyController::purge`).
 *
 * Berbeda dari `CompanyDeletionService` yang cuma menyentuh `deleted_at`, di
 * sini baris `companies` benar-benar dibuang beserta file SQLite tenant-nya.
 * Tabel pusat lain (company_users, tenant_databases, fiscal_years, dst.)
 * ikut terhapus lewat cascade FK; `activity_logs` sengaja `nullOnDelete`
 * sehingga jejak auditnya tetap tinggal.
 *
 * `forceDelete()` (bukan sekadar soft delete kedua) juga yang melepaskan
 * `slug` dan `code` yang UNIQUE, supaya nama yang sama bisa dipakai lagi.
 */
class CompanyPurgeService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @return array{database_path: string|null, file_deleted: bool}
     */
    public function purge(Company $company, ?User $initiator = null): array
    {
        $tenantDatabase = TenantDatabase::query()->where('company_id', $company->id)->first();
        $databasePath = $tenantDatabase?->database_path;

        // Jejak audit ditulis lebih dulu: setelah baris companies hilang,
        // nama dan kodenya tidak bisa dibaca lagi dari mana pun.
        $this->auditLogService->logSuccess([
            'event' => 'companies.purge',
            'action' => 'companies.purge',
            'module' => 'companies',
            'message' => 'Company permanently purged.',
            'record_type' => Company::class,
            'record_id' => $company->id,
            'record_number' => $company->code,
            'metadata' => [
                'name' => $company->name,
                'slug' => $company->slug,
                'deleted_at' => $company->deleted_at?->toDateTimeString(),
                'database_path' => $databasePath,
            ],
            'user_id' => $initiator?->id,
        ], tenant: false);

        DB::transaction(function () use ($company): void {
            $company->forceDelete();
        });

        // File dihapus setelah transaksi commit. Urutan ini disengaja: kalau
        // transaksinya rollback, file tenant masih utuh dan datanya belum
        // hilang — kebalikannya tidak bisa diperbaiki.
        $fileDeleted = false;

        if (is_string($databasePath) && $databasePath !== '' && File::exists($databasePath)) {
            $fileDeleted = File::delete($databasePath);
        }

        return ['database_path' => $databasePath, 'file_deleted' => $fileDeleted];
    }

    /**
     * Perusahaan terhapus yang masa pemulihannya sudah lewat.
     *
     * @return Collection<int, Company>
     */
    public function dueForPurge(?int $retentionDays = null)
    {
        $days = $retentionDays ?? (int) config('companies.deletion_retention_days', 30);

        return Company::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays($days))
            ->orderBy('deleted_at')
            ->get();
    }
}
