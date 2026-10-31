<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiResponse;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Lupa dan reset kata sandi lewat email. Tautan dikirim ke alamat akun, jadi
 * hanya pemilik kotak masuk itu yang bisa mengganti kata sandi.
 */
class PasswordResetController extends Controller
{
    use ApiResponse;

    private const SENT_MESSAGE = 'Jika email terdaftar, tautan atur ulang kata sandi sudah dikirim.';

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        // Akun admin platform memakai pintu login sendiri dan tidak boleh
        // dipulihkan dari sini; akun nonaktif juga tidak diberi tautan.
        $eligible = User::query()
            ->where('email', $data['email'])
            ->where('status', 'active')
            ->where('is_platform_admin', false)
            ->exists();

        if ($eligible) {
            try {
                Password::broker()->sendResetLink(['email' => $data['email']]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Jawaban sama untuk semua kasus agar alamat yang terdaftar tidak bisa ditebak.
        return $this->successResponse(null, self::SENT_MESSAGE);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::broker()->reset(
            $data,
            function (User $user, string $password): void {
                if ($user->is_platform_admin || $user->status !== 'active') {
                    throw ApiException::make('RESET_TOKEN_INVALID', 'Tautan tidak valid atau sudah kedaluwarsa.', 422);
                }

                $user->forceFill(['password' => $password])->save();
                // Semua sesi lama dicabut supaya kata sandi lama benar-benar mati.
                $user->tokens()->delete();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ApiException::make('RESET_TOKEN_INVALID', 'Tautan tidak valid atau sudah kedaluwarsa.', 422);
        }

        return $this->successResponse(null, 'Kata sandi diperbarui. Silakan masuk.');
    }
}
