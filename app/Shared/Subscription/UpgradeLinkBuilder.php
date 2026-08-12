<?php

namespace App\Shared\Subscription;

use App\Shared\Models\Company;
use App\Shared\Models\User;

/**
 * Tautan WhatsApp ke penyedia aplikasi, dipasangkan dengan pesan terisi
 * otomatis. Dipakai di dua tempat: layar `FEATURE_NOT_IN_PLAN` dan editor role
 * di sebelah izin yang terkunci paket — keputusan pemilik produk 2026-08-11.
 * Tanpa ini, gerbang jadi dinding buntu; dengan ini, ia jalur penjualan.
 */
class UpgradeLinkBuilder
{
    public function __construct(
        private readonly PlanOwnerResolver $planOwnerResolver,
    ) {}

    /**
     * `null` kalau nomornya belum diisi di `.env` — pemanggil menyembunyikan
     * tombolnya, bukan menampilkan tautan yang tidak ke mana-mana.
     *
     * `$permission` kosong dipakai editor role, yang menawarkan upgrade tanpa
     * menunjuk satu izin tertentu (bisa beberapa terkunci sekaligus).
     */
    public function buildFor(Company $company, ?string $permission = null): ?string
    {
        $number = (string) config('services.support.whatsapp_number', '');

        if ($number === '') {
            return null;
        }

        $plan = $this->planOwnerResolver->planForCompany($company);
        $planName = $plan?->name ?? 'belum ada paket';

        $message = $permission
            ? sprintf(
                "Halo, saya ingin menaikkan paket untuk %s.\nPaket sekarang: %s.\nFitur yang saya butuhkan: %s.",
                $company->name,
                $planName,
                $permission,
            )
            : sprintf(
                "Halo, saya ingin menaikkan paket untuk %s.\nPaket sekarang: %s.",
                $company->name,
                $planName,
            );

        return 'https://wa.me/'.$number.'?text='.rawurlencode($message);
    }

    /**
     * Tautan renewal untuk layar kedaluwarsa (Fase 3). Beda pesan dari
     * `buildFor()`: client di sini sudah kenal paketnya, yang kurang adalah
     * PERIODE-nya — jadi tidak masuk akal menawarkan "naikkan paket".
     */
    public function renewalLinkFor(User $client): ?string
    {
        $number = (string) config('services.support.whatsapp_number', '');

        if ($number === '') {
            return null;
        }

        $message = sprintf(
            "Halo, langganan saya (%s) sudah berakhir. Saya ingin memperpanjang.\nPaket: %s.",
            $client->name,
            $client->plan?->name ?? 'belum ada paket',
        );

        return 'https://wa.me/'.$number.'?text='.rawurlencode($message);
    }
}
