<?php

namespace App\Shared\Company;

use App\Shared\Audit\AuditLogService;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\Storage\TenantStorageManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

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
        private readonly TenantStorageManager $storages,
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

        // Wadah tenant dibuang setelah transaksi commit. Urutan ini disengaja:
        // kalau transaksinya rollback, data tenant masih utuh dan belum hilang —
        // kebalikannya tidak bisa diperbaiki. Bentuk wadahnya bergantung driver
        // (berkas SQLite atau schema Postgres), jadi diserahkan ke penyimpanannya.
        $fileDeleted = false;

        if ($tenantDatabase !== null) {
            try {
                $fileDeleted = $this->storages->for($tenantDatabase)->drop(
                    (string) $tenantDatabase->database_name,
                    (string) $tenantDatabase->database_path,
                );
            } catch (Throwable $e) {
                // Company-nya sudah benar-benar hilang dari central; kegagalan
                // membuang wadahnya tidak boleh membatalkan purge yang sudah
                // terjadi. Sisanya ditangani `companies:sweep-deleted`.
                report($e);
            }
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
