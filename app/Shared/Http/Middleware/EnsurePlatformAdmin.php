<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\ApiResponseBuilder;
use App\Shared\Auth\TokenAbility;
use Closure;
use Illuminate\Http\Request;

/**
 * Penjaga area pengelolaan client.
 *
 * Dua lapis: flag di akun, dan ability pada token. Token client tidak membawa
 * ability `platform-admin`, jadi sesi client tidak bisa menyentuh endpoint ini
 * walaupun akunnya nanti dijadikan admin.
 *
 * Endpoint di belakang middleware ini tidak boleh memakai `company.access`
 * atau menyentuh koneksi tenant — pengelola aplikasi mengatur akun dan paket,
 * bukan membaca data keuangan client.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponseBuilder::error(ApiErrorCode::UNAUTHENTICATED, null, [], 401);
        }

        if (! $user->is_platform_admin) {
            return ApiResponseBuilder::error(
                ApiErrorCode::FORBIDDEN,
                'Akun ini bukan admin aplikasi.',
                [],
                403
            );
        }

        if (! $user->tokenCan(TokenAbility::PLATFORM_ADMIN)) {
            return ApiResponseBuilder::error(
                ApiErrorCode::FORBIDDEN,
                'Sesi ini bukan sesi admin. Masuk lewat halaman login admin.',
                [],
                403
            );
        }

        return $next($request);
    }
}
