<?php

namespace App\Modules\Companies\Services;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Subscription\UserQuotaService;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class CompanyUserAssignmentService
{
    public function __construct(private readonly UserQuotaService $userQuotaService) {}

    /**
     * @param  array{company_id:int,email:string,role:string}  $input
     */
    public function assign(array $input): CompanyUser
    {
        $companyId = (int) ($input['company_id'] ?? 0);
        $email = trim((string) ($input['email'] ?? ''));
        $role = trim((string) ($input['role'] ?? ''));

        if ($companyId <= 0) {
            throw new InvalidArgumentException('company_id wajib numeric/integer.');
        }

        if ($email === '') {
            throw new InvalidArgumentException('email wajib diisi.');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('email tidak valid.');
        }

        if ($role === '') {
            throw new InvalidArgumentException('role wajib diisi.');
        }

        $allowedRoles = ['owner', 'admin', 'staff', 'viewer'];
        if (! in_array($role, $allowedRoles, true)) {
            throw new InvalidArgumentException('role tidak valid. Allowed: owner, admin, staff, viewer.');
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

        $databasePath = $tenantDatabase->database_path ?: database_path('tenants/'.$tenantDatabase->database_name);
        if (! File::exists($databasePath)) {
            throw new InvalidArgumentException('File SQLite tenant tidak ditemukan.');
        }

        $assignment = CompanyUser::query()
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->first();

        // Batas jumlah user paket ditegakkan di sini karena ini satu-satunya
        // jalur yang menambah anggota perusahaan — provisioning hanya membuat
        // baris owner, dan alur undangan belum punya endpoint penerimaan.
        // Kalau alur itu kelak diselesaikan, endpoint accept-nya wajib lewat
        // pemeriksaan yang sama.
        //
        // Mengubah role user yang sudah aktif tidak menambah siapa pun, jadi
        // tidak ditahan. Mengaktifkan kembali user nonaktif menambah, jadi
        // ditahan sama seperti anggota baru.
        $addsActiveUser = ! $assignment || $assignment->status !== 'active';

        if ($addsActiveUser && ! $this->userQuotaService->canAddUser($company)) {
            $summary = $this->userQuotaService->summaryFor($company);

            throw new InvalidArgumentException(sprintf(
                'Kuota user perusahaan ini sudah penuh (%d dari %d terpakai%s). Naikkan paket pemiliknya untuk menambah user.',
                $summary['used'],
                $summary['limit'],
                $summary['plan_name'] ? ', paket '.$summary['plan_name'] : '',
            ));
        }

        if ($assignment) {
            $assignment->forceFill([
                'role' => $role,
                'status' => 'active',
                'joined_at' => $assignment->joined_at ?: now(),
            ])->save();

            return $assignment;
        }

        return CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }
}
