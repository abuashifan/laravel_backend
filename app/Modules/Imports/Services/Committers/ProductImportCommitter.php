<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Services\Committers\Concerns\DetectsDuplicateCodesInBatch;
use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Services\ProductService;
use App\Shared\Exceptions\ApiException;
use Throwable;

/**
 * "Perlu kategori & satuan sudah ada lebih dulu" (rencana impor data, Fase
 * 1) — importer TIDAK membuat kategori/satuan yang belum ada, hanya mencari
 * yang sudah ada lewat nama (kategori) / kode (satuan). Client yang
 * kategorinya belum ada wajib membuatnya lebih dulu lewat layar Master Data.
 */
class ProductImportCommitter implements ImportProfileCommitter
{
    use DetectsDuplicateCodesInBatch;

    private const TYPES = ['goods', 'service'];

    public function __construct(
        private readonly ProductService $products,
    ) {}

    public function validateRow(ImportBatch $batch, array $normalized): array
    {
        $errors = [];

        $type = trim((string) ($normalized['type'] ?? 'goods'));
        if ($type !== '' && ! in_array($type, self::TYPES, true)) {
            $errors['type'][] = sprintf('Type "%s" tidak dikenal. Pakai salah satu: %s.', $type, implode(', ', self::TYPES));
        }

        $categoryName = trim((string) ($normalized['category'] ?? ''));
        if ($categoryName !== '' && ! ProductCategory::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($categoryName)])->exists()) {
            $errors['category'][] = "Kategori \"{$categoryName}\" tidak dikenal. Buat kategorinya dulu di Master Data.";
        }

        $unitCode = trim((string) ($normalized['unit'] ?? ''));
        $isStockItem = $this->toBool($normalized['stock_item'] ?? null);

        if ($unitCode === '' && $isStockItem) {
            $errors['unit'][] = 'Unit wajib diisi untuk produk berstok.';
        } elseif ($unitCode !== '' && ! Unit::query()->whereRaw('LOWER(code) = ?', [mb_strtolower($unitCode)])->exists()) {
            $errors['unit'][] = "Satuan \"{$unitCode}\" tidak dikenal. Buat satuannya dulu di Master Data.";
        }

        $code = trim((string) ($normalized['code'] ?? ''));
        if ($code !== '') {
            if (Product::query()->where('product_code', $code)->exists()) {
                $errors['code'][] = "Kode produk \"{$code}\" sudah dipakai.";
            } elseif ($this->isCodeUsedElsewhereInBatch($batch, 'code', $code)) {
                $errors['code'][] = "Kode produk \"{$code}\" dipakai lebih dari sekali di berkas ini.";
            }
        }

        return $errors;
    }

    public function commit(ImportBatch $batch): array
    {
        $results = [];

        foreach ($batch->rows()->where('status', 'valid')->orderBy('row_number')->get() as $row) {
            $results[$row->id] = $this->commitRow($row);
        }

        return $results;
    }

    /**
     * @return array{status: string, document_id: ?int, document_type: ?string, error: ?string}
     */
    private function commitRow(ImportRow $row): array
    {
        $normalized = (array) $row->normalized;
        $type = trim((string) ($normalized['type'] ?? 'goods')) ?: 'goods';
        $isStockItem = $this->toBool($normalized['stock_item'] ?? null);

        $categoryName = trim((string) ($normalized['category'] ?? ''));
        $categoryId = $categoryName !== ''
            ? ProductCategory::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($categoryName)])->value('id')
            : null;

        $unitCode = trim((string) ($normalized['unit'] ?? ''));
        $unitId = $unitCode !== ''
            ? Unit::query()->whereRaw('LOWER(code) = ?', [mb_strtolower($unitCode)])->value('id')
            : null;

        try {
            $product = $this->products->create([
                'product_code' => $this->nullableString($normalized['code'] ?? null),
                'product_name' => trim((string) $normalized['name']),
                'product_type' => $type,
                'product_category_id' => $categoryId,
                'unit_id' => $unitId,
                'is_stock_item' => $isStockItem,
                'min_stock' => $this->nullableFloat($normalized['min_stock'] ?? null),
                'is_active' => true,
            ]);

            return ['status' => 'committed', 'document_id' => $product->id, 'document_type' => Product::class, 'error' => null];
        } catch (ApiException $exception) {
            return ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => $exception->getMessage()];
        } catch (Throwable $exception) {
            return ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => 'Gagal membuat produk: '.$exception->getMessage()];
        }
    }

    private function toBool(?string $value): bool
    {
        $value = mb_strtolower(trim((string) $value));

        return in_array($value, ['1', 'ya', 'yes', 'true', 'y'], true);
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableFloat(?string $value): ?float
    {
        $value = trim((string) $value);

        return $value === '' ? null : (float) $value;
    }
}
