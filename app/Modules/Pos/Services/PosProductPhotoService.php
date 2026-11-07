<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosProduct;
use App\Modules\Pos\Models\PosProductPhoto;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Foto produk sebagai data URL di database toko. Aplikasi kasir yang
 * memperkecil gambar; di sini hanya diperiksa bahwa isinya gambar sungguhan,
 * ukurannya wajar, dan total foto toko tidak melebihi kuota.
 */
class PosProductPhotoService
{
    public const MAX_PHOTO_BYTES = 300 * 1024;

    public const MAX_THUMB_BYTES = 24 * 1024;

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function store(PosProduct $product, string $photo, string $thumb): PosProduct
    {
        $photoBytes = $this->decodedSize($photo, self::MAX_PHOTO_BYTES, 'photo');
        $thumbBytes = $this->decodedSize($thumb, self::MAX_THUMB_BYTES, 'thumb');
        $bytes = $photoBytes + $thumbBytes;

        $used = (int) PosProductPhoto::query()->where('product_id', '!=', $product->id)->sum('bytes');
        $quota = $this->quotaBytes();
        if ($used + $bytes > $quota) {
            throw ApiException::make(
                'PHOTO_QUOTA_EXCEEDED',
                'Ruang foto toko sudah penuh ('.(int) round($quota / 1024 / 1024).' MB). Hapus foto produk yang tidak perlu.',
                422,
            );
        }

        DB::connection('pos')->transaction(function () use ($product, $photo, $thumb, $bytes) {
            PosProductPhoto::query()->updateOrCreate(['product_id' => $product->id], ['data' => $photo, 'bytes' => $bytes]);
            $product->update(['photo_thumb' => $thumb]);
        });

        return $product;
    }

    public function delete(PosProduct $product): void
    {
        DB::connection('pos')->transaction(function () use ($product) {
            PosProductPhoto::query()->where('product_id', $product->id)->delete();
            $product->update(['photo_thumb' => null]);
        });
    }

    /** @return array{used_bytes: int, quota_bytes: int} */
    public function usage(): array
    {
        return ['used_bytes' => (int) PosProductPhoto::query()->sum('bytes'), 'quota_bytes' => $this->quotaBytes()];
    }

    private function quotaBytes(): int
    {
        return max(1, (int) config('pos.photo_quota_mb', 50)) * 1024 * 1024;
    }

    private function decodedSize(string $dataUrl, int $max, string $field): int
    {
        $invalid = fn (string $message) => ApiException::make('VALIDATION_ERROR', $message, 422, [$field => [$message]]);

        if (! preg_match('#^data:(image/[a-z]+);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $match) || ! in_array($match[1], self::MIMES, true)) {
            throw $invalid('Foto harus gambar JPG, PNG, atau WEBP.');
        }
        $binary = base64_decode($match[2], true);
        if ($binary === false || $binary === '') {
            throw $invalid('Isi foto tidak terbaca.');
        }
        if (strlen($binary) > $max) {
            throw $invalid('Foto terlalu besar (maks '.(int) ($max / 1024).' KB).');
        }
        $info = @getimagesizefromstring($binary);
        if ($info === false || ($info['mime'] ?? null) !== $match[1]) {
            throw $invalid('Isi foto bukan gambar yang valid.');
        }

        return strlen($binary);
    }
}
