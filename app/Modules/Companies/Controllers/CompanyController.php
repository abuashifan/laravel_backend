<?php

namespace App\Modules\Companies\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Companies\Requests\DeleteCompanyRequest;
use App\Modules\Companies\Services\CompanyCreationService;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ApiResponseBuilder;
use App\Shared\Company\CompanyDeletionService;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Subscription\CompanyQuotaService;
use App\Shared\Subscription\SubscriptionService;
use App\Shared\Subscription\UpgradeLinkBuilder;
use App\Shared\Tenant\ProductActivationService;
use App\Shared\Tenant\TenantProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            ->with('tenantDatabase', 'productTenants')
            ->get()
            ->map(fn (Company $company) => $this->companyPayload($company, $company->pivot?->role, (bool) $company->pivot?->is_owner))
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
        CompanyQuotaService $quotaService,
        SubscriptionService $subscriptionService,
        UpgradeLinkBuilder $upgradeLinkBuilder
    ): JsonResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
            // Produk pertama perusahaan. Bawaan akuntansi (perilaku lama);
            // `pos` membuat perusahaan tanpa tenant akuntansi.
            'product' => ['sometimes', 'string', Rule::in(TenantProductService::products())],
        ]);

        $user = $request->user();
        $name = trim($data['name']);
        $product = (string) ($data['product'] ?? TenantDatabase::PRODUCT_ACCOUNTING);

        // Gerbang langganan. `plan_id` (diatur admin di tab Kuota Paket) cuma
        // menentukan APA yang boleh dipakai KALAU sudah berlangganan — bukan
        // izin pakai itu sendiri. Tanpa gerbang ini, admin menempelkan plan_id
        // saja (tanpa pernah klik "Mulai Langganan") sudah cukup membuka akses
        // penuh selamanya, tanpa catatan billing sama sekali: `state` client
        // itu tetap `none`, yang tidak pernah dianggap terkunci di manapun
        // (lihat SubscriptionService::isLocked()). Login tetap dibuka untuk
        // state itu (client baru wajar belum berlangganan), tapi membuat
        // perusahaan pertama harus menunggu langganan benar-benar dimulai.
        $subscriptionState = $subscriptionService->stateFor($user, $product);
        if (! in_array($subscriptionState, [SubscriptionService::STATE_ACTIVE, SubscriptionService::STATE_GRACE], true)) {
            return ApiResponseBuilder::error(
                ApiErrorCode::SUBSCRIPTION_REQUIRED,
                null,
                [],
                403,
                ['renewal_url' => $upgradeLinkBuilder->renewalLinkFor($user), 'product' => $product]
            );
        }

        // Gerbang kuota. Menurunkan paket tidak mencabut perusahaan yang sudah
        // ada, jadi `used` bisa saja melebihi `limit` — yang ditahan hanya
        // penambahan baru.
        $summary = $quotaService->summaryForProduct($user, $product);
        if (! $summary['can_create']) {
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
            $company = $creationService->createForUser($user, $name, $product);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            // Alasan kegagalan sebenarnya SUDAH diketahui di sini — provisioning
            // dan migrasi tenant mengembalikan pesan yang spesifik — tapi selama
            // ini ditelan dan diganti kalimat generik. Akibatnya kegagalan
            // provisioning hanya bisa didiagnosa lewat log server, yang belum
            // tentu terjangkau saat itu juga.
            //
            // Saat APP_DEBUG menyala, alasan aslinya ikut dikirim ke layar. Di
            // production pesannya tetap generik, karena isi exception bisa
            // memuat detail infrastruktur yang tidak untuk dibaca client.
            return $this->errorResponse(
                config('app.debug')
                    ? 'Gagal menyiapkan database perusahaan: '.$e->getMessage()
                    : 'Gagal menyiapkan database perusahaan. Coba lagi.',
                500
            );
        }

        return $this->successResponse(
            $this->companyPayload($company->load('tenantDatabase', 'productTenants'), 'owner', true),
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
    private function companyPayload(Company $company, ?string $userRole, bool $isOwner = false): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'slug' => $company->slug,
            'code' => $company->code,
            'status' => $company->status,
            'user_role' => $userRole,
            // Pemilik ditentukan `is_owner`, bukan label peran lama.
            'is_owner' => $isOwner,
            // Produk yang aktif di perusahaan ini (`accounting`, `pos`, ...).
            'products' => $company->productTenants
                ->where('status', 'active')
                ->pluck('product')
                ->map(fn ($product) => (string) $product)
                ->sort()
                ->values()
                ->all(),
            'tenant_database' => $company->tenantDatabase ? [
                'database_name' => $company->tenantDatabase->database_name,
                'status' => $company->tenantDatabase->status,
            ] : null,
        ];
    }

    public function select(Request $request): JsonResponse
    {
        // `exists` dibatasi ke perusahaan yang belum terhapus. Aturan `exists`
        // biasa memakai query mentah yang tidak mengenal soft delete, sehingga
        // perusahaan terhapus lolos ke bawah dan baru gagal sebagai 404 dari
        // findOrFail — pesan yang tidak menjelaskan apa pun ke user.
        $data = $request->validate([
            'company_id' => [
                'required',
                'integer',
                Rule::exists('companies', 'id')->whereNull('deleted_at'),
            ],
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

        if (! $tenantDatabase) {
            // Perusahaan yang baru memakai produk lain (mis. POS). Frontend
            // akuntansi menawarkan aktivasi lewat endpoint products/activate.
            return $this->errorCodeResponse(
                'PRODUCT_NOT_ACTIVE',
                'Finlite Accounting belum diaktifkan untuk perusahaan ini.',
                [],
                422,
                ['product' => TenantDatabase::PRODUCT_ACCOUNTING, 'can_activate' => (bool) ($companyUser->is_owner || $companyUser->full_access)]
            );
        }

        if ($tenantDatabase->status !== 'active') {
            return $this->errorResponse('Tenant database company belum aktif.', 422);
        }

        $activeCompany = [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'slug' => $company->slug,
            'code' => $company->code,
            'user_role' => $companyUser->role,
            'is_owner' => (bool) $companyUser->is_owner,
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

    /**
     * Aktifkan produk ekosistem untuk perusahaan yang sudah ada (mis. POS
     * untuk perusahaan akuntansi, atau akuntansi untuk toko POS). Hanya
     * pemilik/akses penuh, dan pemilik perusahaan wajib berlangganan produk
     * tersebut. Di luar `company.access` karena produknya justru belum aktif.
     */
    public function activateProduct(Request $request, int $companyId, string $product, ProductActivationService $activation): JsonResponse
    {
        $company = Company::query()->findOrFail($companyId);
        $denied = $activation->activate($request->user(), $company, $product);
        if ($denied) {
            return $denied;
        }

        $member = $activation->membership($request->user(), $company);

        return $this->successResponse(
            $this->companyPayload($company->load('tenantDatabase', 'productTenants'), $member?->role, (bool) $member?->is_owner),
            'Produk berhasil diaktifkan.'
        );
    }

    /**
     * Hapus perusahaan dari halaman pilih perusahaan.
     *
     * Hanya owner yang boleh, dan nama perusahaan harus diketik ulang persis
     * sama — pola konfirmasi yang sama seperti menghapus repository di GitHub —
     * supaya klik keliru tidak langsung mencabut akses seluruh staf yang
     * diundang ke tenant ini.
     */
    public function destroy(DeleteCompanyRequest $request, int $companyId, CompanyDeletionService $deletionService): JsonResponse
    {
        $user = $request->user();

        $companyUser = CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $companyUser || ! $companyUser->is_owner) {
            return $this->errorCodeResponse(
                'COMPANY_DELETE_NOT_OWNER',
                'Hanya owner yang dapat menghapus perusahaan.',
                [],
                403
            );
        }

        $company = Company::query()->findOrFail($companyId);
        $confirmName = trim((string) $request->validated('confirm_name'));

        if ($company->name !== $confirmName) {
            return $this->validationErrorResponse(
                ['confirm_name' => ['Nama perusahaan tidak cocok.']],
                'Konfirmasi nama perusahaan tidak cocok.'
            );
        }

        $deletionService->delete($company, $user);

        return $this->successResponse(null, 'Perusahaan berhasil dihapus.');
    }
}
