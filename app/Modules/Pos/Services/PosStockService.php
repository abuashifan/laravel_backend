<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosProduct;
use App\Modules\Pos\Models\PosStockMovement;
use Illuminate\Validation\ValidationException;

class PosStockService
{
    public function __construct(private readonly PosSettingService $settings) {}

    /**
     * Ubah stok produk berstok dan catat mutasinya. Produk tanpa `track_stock`
     * diabaikan. Dipanggil di dalam transaksi pemanggil; produk dikunci ulang
     * supaya dua kasir yang menjual barang sama tidak saling menimpa stok.
     */
    public function move(
        PosProduct $product,
        float $delta,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null,
        ?int $userId = null,
        bool $enforceAvailability = false,
    ): ?PosStockMovement {
        if (! $product->track_stock || $delta == 0.0) {
            return null;
        }

        /** @var PosProduct $locked */
        $locked = PosProduct::query()->lockForUpdate()->findOrFail($product->id);
        $after = round((float) $locked->stock + $delta, 3);

        if ($enforceAvailability && $delta < 0 && $after < 0 && ! $this->settings->allowsNegativeStock()) {
            throw ValidationException::withMessages([
                'lines' => ["Stok {$locked->name} tidak cukup (tersisa ".rtrim(rtrim(number_format((float) $locked->stock, 3, '.', ''), '0'), '.').').'],
            ]);
        }

        $locked->forceFill(['stock' => $after])->save();
        $product->setRawAttributes($locked->getAttributes(), true);

        return PosStockMovement::query()->create([
            'product_id' => $locked->id,
            'type' => $type,
            'quantity' => $delta,
            'stock_after' => $after,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => $notes,
            'user_id' => $userId,
        ]);
    }
}
