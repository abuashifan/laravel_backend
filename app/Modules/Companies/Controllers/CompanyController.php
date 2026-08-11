<?php

namespace App\Modules\Companies\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Companies\Services\CompanyCreationService;
use App\Shared\Api\ApiResponse;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Subscription\CompanyQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class CompanyController extends Controller
{
    use ApiResponse;

    public function index(Request $request, CompanyQuotaService $quotaService): JsonResponse
    {
        $user = $request->user();

        $companies = $user->companies()
            ->wherePivot('status', 'active')
            ->with('tenantDatabase')
            ->get()
            ->map(fn (Company $company) => $this->companyPayload($company, $company->pivot?->role))
            ->values();

        // Kuota dikirim di meta, bukan di data, supaya bentuk `data` tetap
        // berupa array perusahaan seperti kontrak yang sudah dipakai frontend.
        return $this->successResponse(
            $companies,
            'Companies retrieved successfully',
            200,
            ['quota' => $quotaService->summaryFor($user)]
        );
    }

    /**
     * Buat perusahaan baru milik user yang sedang login.
     *
     * Sengaja di luar middleware `company.access`: dipanggil dari halaman pilih
     * perusahaan, saat belum ada perusahaan aktif. Tidak ada pengecekan
     * permission karena permission di aplikasi ini selalu per-perusahaan —
     * yang membatasi siapa boleh memakai endpoint ini adalah tertutupnya
     * registrasi mandiri (lihat Modules/Auth/Routes/api.php).
     */
    public function store(
        Request $request,
        CompanyCreationService $creationService,
        CompanyQuotaService $quotaService
    ): JsonResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
        ]);

        $user = $request->user();
        $name = trim($data['name']);

        // Gerbang kuota. Menurunkan paket tidak mencabut perusahaan yang sudah
        // ada, jadi `used` bisa saja melebihi `limit` — yang ditahan hanya
        // penambahan baru.
        if (! $quotaService->canCreate($user)) {
            $summary = $quotaService->summaryFor($user);
            $planLabel = $summary['plan_name'] ?? 'Anda';

            return $this->errorCodeResponse(
                'COMPANY_QUOTA_EXCEEDED',
                sprintf(
                    'Paket %s mencakup %d perusahaan dan Anda sudah memakai %d. Hubungi admin untuk menambah kuota.',
                    $planLabel,
                    $summary['limit'],
                    $summary['used'],
                ),
                [],
                422,
                ['quota' => $summary]
            );
        }

        // Menahan double-submit: tanpa ini dua klik cepat menghasilkan dua
        // perusahaan beserta dua tenant database yang sama-sama kosong.
        $duplicate = $user->companies()
            ->whereRaw('LOWER(companies.name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($duplicate) {
            return $this->validationErrorResponse(
                ['name' => ['Anda sudah punya perusahaan dengan nama ini.']],
                'Nama perusahaan sudah dipakai.'
            );
        }

        try {
            $company = $creationService->createForUser($user, $name);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return $this->errorResponse('Gagal menyiapkan database perusahaan. Coba lagi.', 500);
        }

        return $this->successResponse(
            $this->companyPayload($company->load('tenantDatabase'), 'owner'),
            'Company created successfully',
            201
        );
    }

    /**
     * Bentuk tunggal untuk satu perusahaan, dipakai index() dan store() supaya
     * frontend bisa memakai normalizeCompany() yang sama untuk keduanya.
     *
     * @return array<string, mixed>
     */
    private function companyPayload(Company $company, ?string $userRole): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'slug' => $company->slug,
            'code' => $company->code,
            'status' => $company->status,
            'user_role' => $userRole,
            'tenant_database' => $company->tenantDatabase ? [
                'database_name' => $company->tenantDatabase->database_name,
                'status' => $company->tenantDatabase->status,
            ] : null,
        ];
    }

    public function select(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $user = $request->user();

        $companyUser = CompanyUser::query()
            ->where('company_id', $data['company_id'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $companyUser) {
            return $this->errorResponse('Anda tidak punya akses ke company ini.', 403);
        }

        $company = Company::query()
            ->with('tenantDatabase')
            ->findOrFail($data['company_id']);

        $tenantDatabase = $company->tenantDatabase;

        if (! $tenantDatabase || $tenantDatabase->status !== 'active') {
            return $this->errorResponse('Tenant database company belum aktif.', 422);
        }

        $activeCompany = [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'slug' => $company->slug,
            'code' => $company->code,
            'user_role' => $companyUser->role,
            'tenant_database' => [
                'database_name' => $tenantDatabase->database_name,
                'database_path' => $tenantDatabase->database_path,
                'status' => $tenantDatabase->status,
            ],
        ];

        return $this->successResponse([
            'active_company' => $activeCompany,
        ], 'Company selected successfully');
    }
}
