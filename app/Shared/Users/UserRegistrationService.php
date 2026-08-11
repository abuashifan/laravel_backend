<?php

namespace App\Shared\Users;

use App\Shared\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Pembuatan akun user.
 *
 * Tidak ada registrasi mandiri di aplikasi ini: akun hanya dibuat oleh owner
 * aplikasi lewat `php artisan user:create`. Karena itu tidak ada route HTTP
 * yang memakai service ini — aturan validasinya sengaja ditaruh di sini supaya
 * tetap satu sumber kalau nanti dibuka lagi lewat endpoint admin.
 */
class UserRegistrationService
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @param  array{name:string,email:string,password:string,phone?:string|null}  $data
     */
    public function register(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'status' => 'active',
        ]);
    }
}
