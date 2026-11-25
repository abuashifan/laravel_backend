<?php

namespace App\Shared\Auth;

use App\Shared\Models\User;

/**
 * Ability token Sanctum yang memisahkan sesi client dari sesi admin aplikasi.
 *
 * Ditaruh di Shared karena dipakai dua sisi sekaligus: controller login yang
 * menerbitkan token (Modules\Auth dan Modules\Admin) dan middleware yang
 * menuntutnya (Shared\Http\Middleware). Kalau konstantanya menempel di salah
 * satu controller, middleware Shared jadi bergantung ke Modules.
 */
final class TokenAbility
{
    /**
     * Sesi client. Dituntut `company.access`, sehingga token admin — yang tidak
     * membawanya — tidak bisa membuka data perusahaan mana pun.
     */
    public const CLIENT = 'client';

    /** Sesi yang diterbitkan melalui aplikasi Workspace. */
    public const WORKSPACE = 'workspace';

    public static function isWorkspaceOnly(User $user): bool
    {
        return $user->tokenCan(self::WORKSPACE) && ! $user->tokenCan('*');
    }

    /** Sesi admin aplikasi. Dituntut `platform.admin`. */
    public const PLATFORM_ADMIN = 'platform-admin';
}
