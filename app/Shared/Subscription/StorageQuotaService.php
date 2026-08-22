<?php

namespace App\Shared\Subscription;

use App\Shared\Models\Company;
use App\Shared\Models\Plan;
use App\Shared\Models\TenantDatabase;
use Illuminate\Support\Carbon;

/**
 * Kuota penyimpanan per perusahaan (Fase 4, skema tier) — pengganti
 * `max_transactions_per_month` yang dibuang. Diukur dari ukuran berkas sqlite
 * tenant + berkas impor tersimpan, BUKAN jumlah transaksi: membatasi jumlah
 * transaksi menghukum client paling aktif, justru yang paling bernilai.
 *
 * SENGAJA membaca angka yang sudah diukur (`tenant_databases.size_bytes`),
 * bukan menghitung ulang tiap panggilan. Mengukur ulang di setiap request
 * (apalagi sebelum unggahan impor) berarti stat() atas seluruh berkas tenant
 * setiap kali — pemborosan yang sama seperti alasan lapis paket di-cache per
 * request. Pengukuran sungguhan adalah tugas command harian
 * (`storage:measure`); kelas ini hanya membaca hasilnya dan menambahkan
 * ukuran unggahan yang SEDANG diajukan.
 */
class StorageQuotaService
{
    public function __construct(
        private readonly PlanOwnerResolver $planOwnerResolver,
    ) {}

    public function quotaBytesFor(Company $company): int
    {
        return $this->quotaMbFor($company) * 1024 * 1024;
    }

    /**
     * Sisa MB dari pengukuran harian TERAKHIR — bisa basi sampai satu hari
     * kalau command pengukuran belum sempat berjalan. `0` kalau belum pernah
     * diukur sama sekali, bukan `null`: perusahaan yang benar-benar baru
     * memang belum memakai apa-apa.
     */
    public function usedBytes(Company $company): int
    {
        return (int) ($this->tenantDatabase($company)?->size_bytes ?? 0);
    }

    public function measuredAt(Company $company): ?Carbon
    {
        return $this->tenantDatabase($company)?->measured_at;
    }

    /**
     * Dipanggil SEBELUM menerima unggahan impor — satu-satunya jalur yang
     * bisa menambah penyimpanan secara melonjak. `$incomingBytes` ditambahkan
     * ke angka pengukuran terakhir supaya keputusannya memperhitungkan
     * unggahan yang sedang diajukan, bukan cuma keadaan kemarin.
     */
    public function canAccept(Company $company, int $incomingBytes): bool
    {
        return $this->usedBytes($company) + max(0, $incomingBytes) <= $this->quotaBytesFor($company);
    }

    public function retentionDaysFor(Company $company): int
    {
        $owner = $this->planOwnerResolver->ownerOf($company);

        if (! $owner) {
            return 30;
        }

        $plan = $this->planOwnerResolver->planFor($owner);

        if ($plan?->isCustom() && $owner->import_retention_days !== null) {
            return max(1, (int) $owner->import_retention_days);
        }

        return max(1, (int) ($plan?->import_retention_days ?? 30));
    }

    /**
     * @return array{used_bytes:int, quota_bytes:int, percent_used:float, can_accept:bool, measured_at:?string}
     */
    public function summaryFor(Company $company): array
    {
        $used = $this->usedBytes($company);
        $quota = $this->quotaBytesFor($company);

        return [
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'percent_used' => $quota > 0 ? round(($used / $quota) * 100, 1) : 0.0,
            'can_accept' => $used <= $quota,
            'measured_at' => $this->measuredAt($company)?->toISOString(),
        ];
    }

    private function quotaMbFor(Company $company): int
    {
        $owner = $this->planOwnerResolver->ownerOf($company);

        if (! $owner) {
            return 1024;
        }

        $plan = $this->planOwnerResolver->planFor($owner);

        if ($plan?->isCustom() && $owner->storage_quota_mb !== null) {
            return max(1, (int) $owner->storage_quota_mb);
        }

        return max(1, (int) ($plan?->storage_quota_mb ?? $this->fallbackPlan()?->storage_quota_mb ?? 1024));
    }

    private function fallbackPlan(): ?Plan
    {
        return Plan::query()->where('code', PlanOwnerResolver::DEFAULT_PLAN_CODE)->first();
    }

    private function tenantDatabase(Company $company): ?TenantDatabase
    {
        return $company->tenantDatabase;
    }
}
