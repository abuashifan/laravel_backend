<?php

namespace App\Shared\Subscription;

use App\Shared\Models\Company;

/**
 * Lapis 1 — paket. Menjawab "perusahaan ini punya fitur itu?", terpisah dari
 * lapis 2 (permission) yang menjawab "orang ini boleh menyentuhnya?".
 *
 * Kedua sumbu itu sengaja TIDAK dilebur. Paket dimiliki client yang
 * berlangganan dan mengikat **semua orang di perusahaannya, owner termasuk**;
 * permission dimiliki perusahaan dan hanya mengatur user tambahan. Melebur
 * keduanya membuat pesan errornya salah alamat: "hubungi penyedia aplikasi"
 * dan "hubungi admin perusahaan" adalah dua jalan keluar yang berbeda.
 *
 * Bentuknya sengaja "daftar yang DITUTUP", bukan "daftar yang dibuka". Dua
 * alasan:
 *
 * 1. Ia daftar putih — izin yang tidak disebut di `config/plan_features.php`
 *    selalu terbuka, jadi himpunan yang ditutup selalu kecil dan tidak perlu
 *    membaca seluruh katalog izin.
 * 2. Menghitung "yang dibuka" butuh katalog izin lengkap, dan katalog itu
 *    tinggal di `EffectivePermissionService` — yang justru memanggil kelas ini.
 *    Menyuntikkannya balik akan membuat ketergantungan melingkar.
 */
class PlanPermissionResolver
{
    /** @var array<int, list<string>> */
    private array $blockedCache = [];

    /** @var array<int, list<string>> */
    private array $featureCache = [];

    public function __construct(
        private readonly PlanOwnerResolver $planOwnerResolver,
    ) {}

    /**
     * Fitur yang dibuka paket perusahaan ini. Dikirim juga ke frontend supaya
     * UI bisa membedakan "minta ke admin perusahaan" dari "naikkan paket".
     *
     * @return list<string>
     */
    public function featuresFor(Company $company): array
    {
        if ($company->id !== null && array_key_exists($company->id, $this->featureCache)) {
            return $this->featureCache[$company->id];
        }

        $plan = $this->planOwnerResolver->planForCompany($company);
        $features = array_values(array_filter(
            (array) ($plan?->features ?? []),
            static fn ($feature): bool => is_string($feature) && $feature !== '',
        ));

        if ($company->id !== null) {
            $this->featureCache[$company->id] = $features;
        }

        return $features;
    }

    /**
     * Pola izin yang ditutup paket ini. Akhiran `.*` berarti seluruh awalan.
     *
     * @return list<string>
     */
    public function blockedKeysFor(Company $company): array
    {
        if (! $this->enforcing()) {
            return [];
        }

        if ($company->id !== null && array_key_exists($company->id, $this->blockedCache)) {
            return $this->blockedCache[$company->id];
        }

        $features = $this->featuresFor($company);
        $blocked = [];

        foreach ((array) config('plan_features.features', []) as $feature => $keys) {
            if (in_array((string) $feature, $features, true)) {
                continue;
            }

            foreach ((array) $keys as $key) {
                $blocked[] = (string) $key;
            }
        }

        $blocked = array_values(array_unique($blocked));

        if ($company->id !== null) {
            $this->blockedCache[$company->id] = $blocked;
        }

        return $blocked;
    }

    public function allows(Company $company, string $permissionKey): bool
    {
        foreach ($this->blockedKeysFor($company) as $pattern) {
            if ($this->matches($pattern, $permissionKey)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Menyaring daftar izin yang sudah dihitung lapis 2, membuang yang tidak
     * dibuka paket. Daftar kandidatnya dikirim masuk — lihat catatan kelas soal
     * ketergantungan melingkar.
     *
     * @param  list<string>  $candidateKeys
     * @return list<string>
     */
    public function allowedKeysFor(Company $company, array $candidateKeys): array
    {
        $blocked = $this->blockedKeysFor($company);

        if ($blocked === []) {
            return array_values($candidateKeys);
        }

        return array_values(array_filter(
            $candidateKeys,
            function (string $key) use ($blocked): bool {
                foreach ($blocked as $pattern) {
                    if ($this->matches($pattern, $key)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    public function enforcing(): bool
    {
        return filter_var(config('plan_features.enforce', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Cache-nya berumur satu request. Dipakai test yang mengubah konfigurasi
     * paket di tengah jalan; di jalur produksi tidak ada yang memanggilnya.
     */
    public function flush(): void
    {
        $this->blockedCache = [];
        $this->featureCache = [];
    }

    private function matches(string $pattern, string $permissionKey): bool
    {
        if (str_ends_with($pattern, '.*')) {
            return str_starts_with($permissionKey, substr($pattern, 0, -1));
        }

        return $pattern === $permissionKey;
    }
}
