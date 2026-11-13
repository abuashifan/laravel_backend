<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosAuditLog;
use App\Shared\Models\User;

/**
 * Log audit toko (Fase 4b). Dipanggil di dalam transaksi aksi yang dicatat,
 * jadi aksi yang gagal dan dibatalkan tidak meninggalkan jejak palsu.
 */
class PosAuditService
{
    /** Kunci aksi => label untuk layar log dan filter. */
    public const ACTIONS = [
        'shift.open' => 'Buka shift',
        'shift.close' => 'Tutup shift',
        'sale.void' => 'Batalkan transaksi',
        'sale.return' => 'Retur barang',
        'product.price_change' => 'Ubah harga produk',
        'stock.adjust' => 'Ubah stok',
        'stock.count' => 'Stok opname',
        'discount.override' => 'Diskon di atas batas',
        'pin.changed' => 'Ubah PIN kepala toko',
        'pin.failed' => 'PIN kepala toko salah',
        'settings.update' => 'Ubah pengaturan toko',
    ];

    /**
     * @param  array<string,mixed>  $changes  Data pendukung, mis. ['harga' => [lama, baru]].
     * @param  array{type?:string, id?:int|null, label?:string|null}  $subject
     */
    public function record(
        string $action,
        string $summary,
        ?User $user = null,
        array $subject = [],
        array $changes = [],
        ?string $reason = null,
        bool $approvedWithPin = false,
    ): PosAuditLog {
        return PosAuditLog::query()->create([
            'action' => $action,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'subject_type' => $subject['type'] ?? null,
            'subject_id' => $subject['id'] ?? null,
            'subject_label' => $subject['label'] ?? null,
            'summary' => mb_substr($summary, 0, 255),
            'changes' => $changes === [] ? null : $changes,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'approved_with_pin' => $approvedWithPin,
        ]);
    }
}
