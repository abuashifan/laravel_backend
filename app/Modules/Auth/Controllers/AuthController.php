<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\LoginRequest;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ApiResponseBuilder;
use App\Shared\Auth\TokenAbility;
use App\Shared\Models\User;
use App\Shared\Subscription\SubscriptionService;
use App\Shared\Subscription\UpgradeLinkBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly UpgradeLinkBuilder $upgradeLinkBuilder,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        if ($user->status !== 'active') {
            return $this->errorResponse('Akun tidak aktif.', 403);
        }

        // Akun pengelola aplikasi tidak boleh masuk lewat pintu client. Sesi
        // yang lahir di sini hanya membawa ability `client`, jadi token dari
        // pintu ini tidak akan pernah bisa memanggil endpoint /admin.
        if ($user->is_platform_admin) {
            return $this->errorResponse('Akun ini admin aplikasi. Masuk lewat halaman login admin.', 403);
        }

        // Kunci penuh di titik login, bukan di EnsureCompanyAccess (Fase 3
        // §4c): penguncian berlaku ke CLIENT, bukan ke perusahaan, dan
        // menolak di sini memberi satu tempat untuk menampilkan tautan
        // WhatsApp. Client yang belum pernah berlangganan sama sekali
        // (state `none`) TIDAK terkunci — lihat SubscriptionService::stateFor().
        if ($this->subscriptionService->isLocked($user)) {
            return ApiResponseBuilder::error(
                ApiErrorCode::SUBSCRIPTION_EXPIRED,
                null,
                [],
                403,
                ['renewal_url' => $this->upgradeLinkBuilder->renewalLinkFor($user)]
            );
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('api-token', [TokenAbility::CLIENT])->plainTextToken;

        return $this->successResponse([
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
            'subscription' => $this->subscriptionSummary($user),
        ], 'Login berhasil');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'user' => $user,
            'subscription' => $this->subscriptionSummary($user),
        ], 'User retrieved successfully');
    }

    /**
     * Ringkasan untuk spanduk peringatan H-14/tenggang (Fase 3 §4f). `null`
     * kalau state `none` — client yang belum pernah berlangganan tidak perlu
     * melihat spanduk apa pun. Staf (bukan pemilik langganan) juga selalu
     * `none` di sini, jadi mereka tidak melihat spanduk milik client-nya —
     * batasan yang sama dengan penguncian login, lihat catatan di `login()`.
     *
     * @return array{state:string, ends_at:?string, days_remaining:?int, renewal_url:?string}|null
     */
    private function subscriptionSummary(User $user): ?array
    {
        $state = $this->subscriptionService->stateFor($user);

        if ($state === SubscriptionService::STATE_NONE) {
            return null;
        }

        return [
            'state' => $state,
            'ends_at' => $this->subscriptionService->currentFor($user)?->ends_at?->toISOString(),
            'days_remaining' => $this->subscriptionService->daysRemaining($user),
            'renewal_url' => $this->upgradeLinkBuilder->renewalLinkFor($user),
        ];
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->successResponse(null, 'Logout berhasil');
    }
}
