<?php

namespace App\Modules\Gudang\Controllers;

use App\Shared\Models\Plan;
use App\Shared\Models\User;
use App\Shared\Subscription\SubscriptionService;
use App\Shared\Users\UserRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SignupController extends GudangController
{
    public function code(Request $request)
    {
        $email = mb_strtolower($request->validate(['email'=>'required|email|max:255'])['email']);
        $code = Str::upper(Str::random(12));
        Cache::put('gudang-signup:'.hash('sha256',$email),Hash::make($code),now()->addMinutes(15));
        Mail::raw("Kode verifikasi Finlite Gudang Anda: $code\nKode berlaku selama 15 menit.", fn ($mail) => $mail->to($email)->subject('Kode verifikasi Finlite Gudang'));
        return $this->successResponse(true,'Cek email untuk kode verifikasi.');
    }

    public function register(Request $request, UserRegistrationService $registration, SubscriptionService $subscriptions)
    {
        $data = $request->validate(['email'=>'required|email|max:255','name'=>'required|string|max:255','password'=>'required|string|min:8|max:255','code'=>'required|string|size:12']);
        $email = mb_strtolower($data['email']);
        $key = 'gudang-signup:'.hash('sha256',$email);
        $hash = Cache::get($key);
        abort_unless($hash && Hash::check(strtoupper($data['code']),$hash),422,'Kode salah atau kedaluwarsa.');
        DB::transaction(function () use ($email,$data,$registration,$subscriptions) {
            $user = User::query()->where('email',$email)->lockForUpdate()->first();
            if ($user) {
                abort_unless($user->status === 'active' && Hash::check($data['password'],$user->password),422,'Gunakan kata sandi akun Finlite Anda.');
            } else {
                $user = $registration->register(['email'=>$email,'name'=>$data['name'],'password'=>$data['password']]);
            }
            $user->forceFill(['email_verified_at'=>$user->email_verified_at ?? now()])->save();
            $plan = Plan::query()->where('code',Plan::GUDANG_CODE)->where('status','active')->firstOrFail();
            $subscriptions->startTrial($user,$plan,14);
        },3);
        Cache::forget($key);
        return $this->successResponse(true,'Email terverifikasi. Silakan masuk.');
    }
}
