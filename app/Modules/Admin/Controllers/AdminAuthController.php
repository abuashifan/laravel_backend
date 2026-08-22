<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiResponse;
use App\Shared\Auth\TokenAbility;
use App\Shared\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Pintu masuk terpisah untuk admin aplikasi.
 *
 * Terpisah bukan hanya secara halaman: token yang lahir di sini membawa
 * ability `platform-admin` dan tidak membawa `client`, sehingga sesi admin
 * ditolak `company.access` dan tidak bisa membuka data perusahaan siapa pun.
 */
class AdminAuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        if ($user->status !== 'active') {
            return $this->errorResponse('Akun tidak aktif.', 403);
        }

        // Pesannya sengaja tidak membedakan "bukan admin" dari "password salah"
        // lebih jauh dari ini, supaya halaman admin tidak bisa dipakai menebak
        // akun mana yang punya hak istimewa.
        if (! $user->is_platform_admin) {
            return $this->errorResponse('Akun ini tidak punya akses admin aplikasi.', 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('admin-token', [TokenAbility::PLATFORM_ADMIN])->plainTextToken;

        return $this->successResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_platform_admin' => true,
            ],
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Login admin berhasil');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_platform_admin' => true,
            ],
        ], 'Admin retrieved successfully');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->successResponse(null, 'Logout berhasil');
    }
}
