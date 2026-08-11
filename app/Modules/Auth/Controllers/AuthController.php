<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\LoginRequest;
use App\Shared\Api\ApiResponse;
use App\Shared\Auth\TokenAbility;
use App\Shared\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

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

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('api-token', [TokenAbility::CLIENT])->plainTextToken;

        return $this->successResponse([
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Login berhasil');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->successResponse([
            'user' => $request->user(),
        ], 'User retrieved successfully');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->successResponse(null, 'Logout berhasil');
    }
}
