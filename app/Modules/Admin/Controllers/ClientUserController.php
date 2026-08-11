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

    public function store(Request $request, UserRegistrationService $registrationService): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
        ]);

        $user = $registrationService->register($data);

        if (! empty($data['plan_id'])) {
            $user->forceFill(['plan_id' => (int) $data['plan_id']])->save();
        }

        return $this->successResponse($this->service->payload($user), 'Client created successfully', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->client($id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        $user->fill($data)->save();

        return $this->successResponse($this->service->payload($user->refresh()), 'Client updated successfully');
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
