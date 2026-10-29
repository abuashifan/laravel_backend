<?php

namespace App\Modules\Companies\Services;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Permission;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Permission\CompanyAccessService;
use App\Shared\Permission\PermissionCatalogService;
use App\Shared\Subscription\UserQuotaService;
use App\Shared\Tenant\Storage\TenantStorageManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CompanyUserAssignmentService
{
    public function __construct(
        private readonly UserQuotaService $userQuotaService,
        private readonly TenantStorageManager $storages,
        private readonly CompanyAccessService $access,
        private readonly PermissionCatalogService $catalog,
    ) {}

    /**
     * @param  array{company_id:int,email:string,full_access:bool,permissions:list<string>}  $input
     */
    public function assign(array $input): CompanyUser
    {
        $companyId = (int) ($input['company_id'] ?? 0);
        $email = trim((string) ($input['email'] ?? ''));
        $fullAccess = (bool) ($input['full_access'] ?? false);
        $keys = array_values(array_unique($input['permissions'] ?? []));
        if (array_diff($keys, (array) config('permissions.matrix_keys', []))) {
            throw new InvalidArgumentException('Izin tidak dikenal dalam katalog matriks.');
        }

        if ($companyId <= 0) {
            throw new InvalidArgumentException('company_id wajib numeric/integer.');
        }

        if ($email === '') {
            throw new InvalidArgumentException('email wajib diisi.');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('email tidak valid.');
        }

        $company = Company::query()->find($companyId);
        if (! $company) {
            throw new InvalidArgumentException('Company tidak ditemukan.');
        }

        if (isset($company->status) && $company->status !== 'active') {
            throw new InvalidArgumentException('Company tidak aktif.');
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            throw new InvalidArgumentException('User tidak ditemukan.');
        }

        $tenantDatabase = TenantDatabase::query()->where('company_id', $company->id)->first();
        if (! $tenantDatabase) {
            throw new InvalidArgumentException('Tenant database tidak ditemukan.');
        }

        if (isset($tenantDatabase->status) && $tenantDatabase->status !== 'active') {
            throw new InvalidArgumentException('Tenant database belum aktif.');
        }

        // Keberadaan wadah tenant ditanyakan ke penyimpanannya. Pemeriksaan
        // `File::exists()` yang dulu ada di sini selalu gagal saat tenant
        // disimpan sebagai schema Postgres, sehingga setiap penambahan anggota
        // perusahaan ditolak dengan alasan yang menyesatkan.
        $storage = $this->storages->for($tenantDatabase);

        if (! $storage->exists((string) $tenantDatabase->database_name, (string) $tenantDatabase->database_path)) {
            throw new InvalidArgumentException(
                $storage->driver() === 'sqlite'
                    ? 'File SQLite tenant tidak ditemukan.'
                    : 'Schema Postgres tenant tidak ditemukan.'
            );
        }

        return DB::transaction(function () use ($company, $user, $fullAccess, $keys) {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $assignment = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->lockForUpdate()->first();

            // Perubahan akses anggota aktif tidak menambah kuota; aktivasi ulang
            // dan penambahan anggota baru membutuhkan slot tersedia.
            $addsActiveUser = ! $assignment || $assignment->status !== 'active';

            if ($addsActiveUser && ! $this->userQuotaService->canAddUser($company)) {
                $summary = $this->userQuotaService->summaryFor($company);

                // Add-on disebut terpisah dari paket: kalau tidak, angka batasnya
                // tidak cocok dengan `max_users` paket dan terbaca seperti bug.
                $origin = $summary['plan_name'] ? ', paket '.$summary['plan_name'] : '';
                if ($summary['extra_users'] > 0) {
                    $origin .= sprintf(' + add-on %d user', $summary['extra_users']);
                }

                throw new InvalidArgumentException(sprintf(
                    'Kuota user perusahaan ini sudah penuh (%d dari %d terpakai%s). Naikkan paket pemiliknya atau tambah add-on user.',
                    $summary['used'],
                    $summary['limit'],
                    $origin,
                ));
            }

            if ($assignment?->is_owner && ! $fullAccess) {
                throw new InvalidArgumentException('Pemilik harus memiliki akses penuh.');
            }
            if (! $assignment) {
                $assignment = CompanyUser::query()->create([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'role' => 'viewer',
                    'full_access' => false,
                    'is_owner' => false,
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            } else {
                $assignment->forceFill(['status' => 'active', 'joined_at' => $assignment->joined_at ?: now()])->save();
            }
            foreach ($keys as $key) {
                Permission::query()->firstOrCreate(['key' => $key], $this->catalog->fromKey($key));
            }
            $this->access->update($assignment, $fullAccess, $keys, null, 'access.permissions.command');

            return $assignment->refresh();
        });
    }
}
