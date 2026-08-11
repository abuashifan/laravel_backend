<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Services\ClientUserService;
use App\Shared\Api\ApiResponse;
use App\Shared\Models\Plan;
use App\Shared\Models\User;
use App\Shared\Users\UserRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientUserController extends Controller
{
    use ApiResponse;

    /** Data kontak client — semuanya opsional, diisi seadanya oleh owner aplikasi. */
    private const PROFILE_RULES = [
        'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
        'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
        'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
        'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
    ];

    /**
     * `company_quota` boleh 0 (mengunci client dari membuat perusahaan baru)
     * dan dibatasi di angka wajar supaya salah ketik tidak menjadi kuota
     * ribuan tenant database.
     */
    private const SUBSCRIPTION_RULES = [
        'plan_id' => ['sometimes', 'nullable', 'integer', 'exists:plans,id'],
        'company_quota' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
        // Minimal 1: perusahaan selalu punya owner, jadi batas nol tidak masuk
        // akal dan hanya akan membuat perusahaan yang ada jadi over-quota.
        'user_quota' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
    ];

    public function __construct(private readonly ClientUserService $service) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->service->paginate([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'plan_id' => $request->query('plan_id'),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 25),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (User $user) => $this->service->payload($user))
        );

        return $this->listResponse($paginator, $request, 'Clients retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse(
            $this->service->payload($this->client($id)),
            'Client retrieved successfully'
        );
    }

    public function store(Request $request, UserRegistrationService $registrationService): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            ...self::PROFILE_RULES,
            ...self::SUBSCRIPTION_RULES,
        ]);

        $user = $registrationService->register($data);
        $user->forceFill($this->extraAttributes($data, null))->save();

        return $this->successResponse($this->service->payload($user), 'Client created successfully', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->client($id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive', 'suspended'])],
            ...self::PROFILE_RULES,
            ...self::SUBSCRIPTION_RULES,
        ]);

        $user->fill(array_intersect_key($data, array_flip(['name', 'email', 'status'])))->save();
        $user->forceFill($this->extraAttributes($data, $user->plan_id))->save();

        return $this->successResponse($this->service->payload($user->refresh()), 'Client updated successfully');
    }

    /**
     * Nilai yang perlu diset eksplisit ke null saat dikosongkan, sehingga tidak
     * bisa lewat `fill()` biasa: mengosongkan kuota khusus berarti "kembali
     * ikut paket", bukan "biarkan seperti sebelumnya".
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extraAttributes(array $data, ?int $currentPlanId): array
    {
        $attributes = [];

        foreach (['phone', 'company_name', 'job_title', 'address', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] !== '' ? $data[$field] : null;
            }
        }

        $planId = array_key_exists('plan_id', $data) ? ($data['plan_id'] ?: null) : $currentPlanId;

        if (array_key_exists('plan_id', $data)) {
            $attributes['plan_id'] = $planId;
        }

        foreach (['company_quota', 'user_quota'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] !== null && $data[$field] !== ''
                    ? (int) $data[$field]
                    : null;
            }
        }

        // Angka kuota hanya bermakna di tier Custom. Untuk tier bertingkat,
        // sisa angka lama dibersihkan supaya data tersimpan tidak menyiratkan
        // batas yang sebenarnya tidak dipakai saat menghitung.
        if (! $this->isCustomPlan($planId)) {
            $attributes['company_quota'] = null;
            $attributes['user_quota'] = null;
        }

        return $attributes;
    }

    private function isCustomPlan(?int $planId): bool
    {
        if ($planId === null) {
            return false;
        }

        return Plan::query()->whereKey($planId)->value('code') === Plan::CUSTOM_CODE;
    }

    /**
     * Mengubah paket hanya menggeser batas pembuatan perusahaan berikutnya.
     * Perusahaan yang sudah ada tidak pernah dicabut — client tidak boleh
     * kehilangan akses ke data yang sudah dia isi hanya karena turun paket.
     */
    public function updatePlan(Request $request, int $id): JsonResponse
    {
        $user = $this->client($id);

        $data = $request->validate([
            'plan_id' => ['present', 'nullable', 'integer', 'exists:plans,id'],
        ]);

        $user->forceFill(['plan_id' => $data['plan_id'] ?: null])->save();

        return $this->successResponse($this->service->payload($user->refresh()), 'Client plan updated successfully');
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $user = $this->client($id);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user->forceFill(['password' => $data['password']])->save();

        // Semua sesi client dicabut supaya password lama benar-benar mati.
        $user->tokens()->delete();

        return $this->successResponse(null, 'Password client berhasil direset');
    }

    public function plans(): JsonResponse
    {
        $plans = Plan::query()
            ->where('status', 'active')
            ->orderBy('max_companies')
            ->get()
            ->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'max_companies' => (int) $plan->max_companies,
                'max_users' => (int) $plan->max_users,
                // Menandai tier yang jumlah perusahaannya diisi manual, supaya
                // frontend tidak perlu mencocokkan string kodenya sendiri.
                'is_custom' => $plan->isCustom(),
            ])
            ->values();

        return $this->successResponse($plans, 'Plans retrieved successfully');
    }

    /**
     * Akun admin aplikasi tidak bisa disentuh lewat endpoint ini — termasuk
     * akun admin yang sedang login. Tanpa penjaga ini, admin bisa
     * menonaktifkan dirinya sendiri dan terkunci di luar aplikasi.
     */
    private function client(int $id): User
    {
        return User::query()
            ->where('is_platform_admin', false)
            ->findOrFail($id);
    }
}
