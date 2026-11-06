<?php

namespace App\Modules\Pos\Controllers;

use App\Mail\PosVerifyEmailMail;
use App\Shared\Auth\TokenAbility;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Plan;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Subscription\SubscriptionService;
use App\Shared\Users\UserRegistrationService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Pendaftaran mandiri Finlite Kasir (Fase 2).
 *
 * Akun lahir tanpa langganan apa pun. Masa coba Kasir baru dimulai setelah
 * email terbukti milik pendaftar, dan membuat toko (tenant POS) butuh
 * langganan aktif -- jadi email palsu tidak bisa membuat tenant. Finlite
 * Accounting tetap tanpa pendaftaran publik: akun ini tidak punya langganan
 * akuntansi sampai admin memberinya.
 */
class PosSignupController extends PosController
{
    /** Masa berlaku tautan verifikasi email. */
    private const VERIFY_TTL_HOURS = 48;

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function register(Request $request, UserRegistrationService $registration): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:50'],
        ], [
            'email.unique' => 'Email ini sudah terdaftar. Silakan masuk atau pakai Lupa kata sandi.',
        ]);

        $user = DB::transaction(fn () => $registration->register([
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $data['password'],
            'phone' => $data['phone'] ?? null,
        ]));

        $mailSent = $this->sendVerification($user);
        $user->forceFill(['last_login_at' => now()])->save();

        return $this->successResponse([
            'user' => $user,
            'token' => $user->createToken('api-token', [TokenAbility::CLIENT])->plainTextToken,
            'token_type' => 'Bearer',
            'subscription' => null,
            'mail_sent' => $mailSent,
        ], 'Akun dibuat. Cek email untuk verifikasi.', 201);
    }

    /** Status akun untuk aplikasi kasir: email terverifikasi dan langganan Kasir. */
    public function account(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return $this->successResponse($this->accountPayload($user));
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user->email_verified_at !== null) {
            return $this->successResponse($this->accountPayload($user), 'Email sudah terverifikasi.');
        }

        if (! $this->sendVerification($user)) {
            throw ApiException::make('MAIL_FAILED', 'Email verifikasi gagal dikirim. Coba lagi beberapa saat lagi.', 503);
        }

        return $this->successResponse($this->accountPayload($user), 'Email verifikasi dikirim ulang.');
    }

    /**
     * Tautan di email membawa token terenkripsi (id, email, kedaluwarsa).
     * Tidak butuh login: tautan bisa dibuka di HP lain. Ganti email = token lama mati.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:2000']]);

        try {
            $payload = json_decode(Crypt::decryptString($data['token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ApiException::make('VERIFY_TOKEN_INVALID', 'Tautan verifikasi tidak valid.', 422);
        }

        $user = User::query()->find($payload['uid'] ?? null);
        if (! $user || strcasecmp($user->email, (string) ($payload['email'] ?? '')) !== 0) {
            throw ApiException::make('VERIFY_TOKEN_INVALID', 'Tautan verifikasi tidak valid.', 422);
        }
        if ((int) ($payload['exp'] ?? 0) < now()->timestamp) {
            throw ApiException::make('VERIFY_TOKEN_EXPIRED', 'Tautan verifikasi sudah kedaluwarsa. Minta kirim ulang dari aplikasi.', 422);
        }

        DB::transaction(function () use ($user): void {
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            // Trial hanya untuk akun yang belum pernah berlangganan Kasir.
            $plan = Plan::query()->where('code', Plan::POS_CODE)->first();
            if ($plan) {
                $this->subscriptions->startTrial($user, $plan, Plan::POS_TRIAL_DAYS);
            }
        });

        return $this->successResponse($this->accountPayload($user->refresh()), 'Email terverifikasi. Masa coba Kasir dimulai.');
    }

    public static function verificationToken(User $user): string
    {
        return Crypt::encryptString((string) json_encode([
            'uid' => $user->id,
            'email' => $user->email,
            'exp' => now()->addHours(self::VERIFY_TTL_HOURS)->timestamp,
        ]));
    }

    private function sendVerification(User $user): bool
    {
        $url = config('app.pos_frontend_url').'/verify-email?token='.urlencode(self::verificationToken($user));

        try {
            Mail::to($user->email)->send(new PosVerifyEmailMail($user->name, $url, self::VERIFY_TTL_HOURS));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** @return array<string, mixed> */
    private function accountPayload(User $user): array
    {
        return [
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'subscription' => $this->subscriptions->summaryFor($user, TenantDatabase::PRODUCT_POS),
        ];
    }
}
