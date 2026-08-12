<?php

namespace App\Shared\Subscription;

use App\Shared\Models\Company;
use App\Shared\Models\Plan;
use App\Shared\Models\User;

/**
 * Rantai tunggal "perusahaan → pemilik → paket".
 *
 * Sebelumnya rantai ini ditulis dua kali (`CompanyQuotaService::planFor()` dan
 * `UserQuotaService::ownerOf()`), dan lapis paket di Fase 1 akan jadi pemakai
 * ketiga. Tiga salinan aturan "paket siapa yang berlaku" berarti tiga tempat
 * yang bisa menjawab berbeda; disatukan di sini supaya kuota perusahaan, kuota
 * user, dan gerbang fitur selalu membaca paket yang sama.
 */
class PlanOwnerResolver
{
    /**
     * Paket yang dipakai kalau client belum diberi paket. Sengaja paket
     * terbatas, bukan tanpa batas — kalau owner aplikasi lupa mengaturnya,
     * efeknya menahan, bukan membuka.
     */
    public const DEFAULT_PLAN_CODE = 'free';

    /**
     * Pemilik dibaca dari `companies.created_by`, bukan dari baris ber-role
     * owner di `company_users`: baris owner bisa lebih dari satu kalau ada
     * owner kedua yang di-assign, dan paket siapa yang berlaku jadi ambigu.
     */
    public function ownerOf(Company $company): ?User
    {
        if (! $company->created_by) {
            return null;
        }

        return User::query()->with('plan')->find($company->created_by);
    }

    public function planFor(User $user): ?Plan
    {
        return $user->plan ?? Plan::query()->where('code', self::DEFAULT_PLAN_CODE)->first();
    }

    /**
     * Paket yang berlaku untuk sebuah perusahaan — yaitu paket pemiliknya.
     * `null` hanya kalau perusahaan tidak punya pemilik **dan** paket Free pun
     * belum di-seed.
     */
    public function planForCompany(Company $company): ?Plan
    {
        $owner = $this->ownerOf($company);

        return $owner ? $this->planFor($owner) : null;
    }
}
