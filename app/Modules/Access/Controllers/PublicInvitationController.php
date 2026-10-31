<?php

namespace App\Modules\Access\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Services\InvitationAcceptanceService;
use App\Shared\Api\ApiResponse;
use App\Shared\Auth\TokenAbility;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Models\User;
use App\Shared\Users\UserRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tautan undangan yang dibuka sebelum login. Akun staf hanya bisa lahir dari
 * sini: email dikunci ke alamat yang diundang, dan kepemilikan email sudah
 * terbukti karena tautannya diterima di kotak masuk itu.
 */
class PublicInvitationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly InvitationAcceptanceService $acceptance) {}

    public function show(string $token): JsonResponse
    {
        $invitation = $this->acceptance->findUsable($token);

        return $this->successResponse([
            'email' => $invitation->email,
            'company_name' => Company::query()->whereKey($invitation->company_id)->value('name'),
            'expires_at' => $invitation->expires_at,
            'has_account' => User::query()->where('email', $invitation->email)->exists(),
        ]);
    }

    public function register(Request $request, string $token, UserRegistrationService $registration): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($token, $data, $registration) {
            $invitation = $this->acceptance->findUsable($token);

            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ApiException::make('ACCOUNT_EXISTS', 'Akun dengan email ini sudah ada. Silakan masuk.', 422);
            }

            $user = $registration->register([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->acceptance->accept($token, $user);

            return $user;
        });

        return $this->successResponse([
            'user' => $user,
            'token' => $user->createToken('api-token', [TokenAbility::CLIENT])->plainTextToken,
            'token_type' => 'Bearer',
            'subscription' => null,
        ], 'Akun dibuat dan undangan diterima.', 201);
    }
}
