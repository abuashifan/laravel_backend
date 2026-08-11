<?php

namespace App\Modules\Companies\Services;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantMigrationService;
use App\Shared\Tenant\TenantProvisioningService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pembuatan perusahaan oleh user dari halaman pilih perusahaan.
 *
 * Semua kerja provisioning sudah ada di TenantProvisioningService (baris
 * companies + company_users + file SQLite tenant) dan TenantMigrationService
 * (menjalankan migrasi tenant). Service ini hanya merangkai keduanya,
 * menghasilkan slug unik, dan membereskan sisa provisioning kalau migrasinya
 * gagal.
 */
class CompanyCreationService
{
    public function __construct(
        private readonly TenantProvisioningService $provisioningService,
        private readonly TenantMigrationService $migrationService,
    ) {}

    public function createForUser(User $owner, string $name): Company
    {
        $name = trim($name);
        $slug = $this->generateUniqueSlug($name);

        $result = $this->provisioningService->provision($name, $slug, $owner->email);

        /** @var Company $company */
        $company = $result['company'];

        $migration = $this->migrationService->migrateCompany($company->id);

        if (! ($migration['success'] ?? false)) {
            // provision() sudah commit, jadi sisa-sisanya dibersihkan di sini.
            // Tanpa ini user berakhir punya perusahaan dengan tenant database
            // kosong yang tetap lolos POST /companies/select — endpoint itu
            // hanya memeriksa tenant_databases.status, bukan isi skemanya.
            $this->rollbackProvisioning($company, $result['database_path'] ?? null);

            throw new RuntimeException(
                'Migrasi tenant gagal: '.($migration['reason'] ?? 'Unknown error')
            );
        }

        return $company->refresh();
    }

    /**
     * Slug dasar diambil dari nama. Kolom `slug` UNIQUE dan tabel companies
     * memakai SoftDeletes, jadi slug milik perusahaan terhapus tetap memblokir
     * dan harus ikut dicek lewat withTrashed().
     */
    private function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'perusahaan';
        }

        $base = Str::limit($base, 80, '');
        $candidate = $base;
        $suffix = 1;

        while (Company::withTrashed()->where('slug', $candidate)->exists()) {
            $suffix++;
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }

    private function rollbackProvisioning(Company $company, ?string $databasePath): void
    {
        if (is_string($databasePath) && $databasePath !== '' && File::exists($databasePath)) {
            File::delete($databasePath);
        }

        TenantDatabase::query()->where('company_id', $company->id)->delete();
        CompanyUser::query()->where('company_id', $company->id)->delete();

        // forceDelete: soft delete tidak melepaskan slug/code yang UNIQUE,
        // sehingga percobaan berikutnya dengan nama yang sama ikut gagal.
        $company->forceDelete();
    }
}
