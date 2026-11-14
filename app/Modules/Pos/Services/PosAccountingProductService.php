<?php

namespace App\Modules\Pos\Services;

use App\Modules\MasterData\Models\Product;
use App\Modules\Pos\Models\PosCategory;
use App\Modules\Pos\Models\PosProduct;
use App\Shared\Models\Company;
use App\Shared\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ambil produk dari Finlite Accounting ke Kasir (Fase 5c, opsional, sekali jalan).
 *
 * Bukan sinkronisasi: produk disalin SEKALI sebagai produk Kasir baru dan
 * sesudahnya berdiri sendiri. Yang disalin hanya nama, kode (jadi SKU),
 * kategori, satuan, harga jual, dan harga beli (jadi modal). Stok tidak
 * disalin dan tidak dilacak sampai pemilik mengisinya lewat stok masuk atau
 * opname, jadi nilai persediaan tidak tercatat dua kali. Produk yang SKU-nya
 * sudah ada di Kasir dilewati, tidak pernah ditimpa. Koneksi tenant akuntansi
 * disambungkan oleh pemanggil (`PosBridgeService::accountChoices()` memakai
 * pola yang sama).
 */
class PosAccountingProductService
{
    public function __construct(
        private readonly PosPlanService $plans,
        private readonly PosAuditService $audit,
    ) {}

    /**
     * Produk Akuntansi yang aktif beserta tanda sudah ada di Kasir.
     *
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        $existing = PosProduct::query()->whereNotNull('sku')->pluck('sku')->map(fn ($sku) => mb_strtolower((string) $sku))->flip();

        return Product::query()->with(['category:id,name', 'unit:id,name'])->where('is_active', true)->orderBy('product_name')->limit(1000)->get()
            ->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'code' => $product->product_code,
                'name' => $product->product_name,
                'category' => $product->category?->name,
                'unit' => $product->unit?->name,
                'price' => (float) $product->selling_price,
                'cost' => (float) $product->purchase_price,
                'in_pos' => isset($existing[mb_strtolower((string) $product->product_code)]),
            ])->all();
    }

    /**
     * @param  list<int>|null  $ids  Null = semua produk aktif yang belum ada di Kasir.
     * @return array{created:int, skipped:int, over_quota:int}
     */
    public function import(?array $ids, ?Company $company, ?User $user): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'over_quota' => 0];

        DB::connection('pos')->transaction(function () use ($ids, $company, $user, &$result) {
            $limit = $company ? $this->plans->planForCompany($company)?->max_products : null;
            $room = $limit === null ? PHP_INT_MAX : max(0, $limit - PosProduct::query()->count());
            $existing = PosProduct::query()->whereNotNull('sku')->pluck('sku')->map(fn ($sku) => mb_strtolower((string) $sku))->flip()->all();
            $categories = [];

            $products = Product::query()->with(['category:id,name', 'unit:id,name'])->where('is_active', true)
                ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
                ->orderBy('product_name')->get();

            foreach ($products as $product) {
                $sku = mb_substr(trim((string) $product->product_code), 0, 50);
                if ($sku !== '' && isset($existing[mb_strtolower($sku)])) {
                    $result['skipped']++;

                    continue;
                }
                if ($room <= 0) {
                    $result['over_quota']++;

                    continue;
                }

                $categoryId = null;
                if ($product->category?->name) {
                    $key = mb_strtolower($product->category->name);
                    $categories[$key] ??= PosCategory::query()->whereRaw('lower(name) = ?', [$key])->value('id')
                        ?? PosCategory::query()->create(['name' => $product->category->name])->id;
                    $categoryId = $categories[$key];
                }

                PosProduct::query()->create([
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => mb_substr((string) $product->product_name, 0, 150),
                    'category_id' => $categoryId,
                    'unit' => $product->unit?->name ? mb_substr($product->unit->name, 0, 20) : 'pcs',
                    'price' => (float) $product->selling_price,
                    'cost' => (float) $product->purchase_price,
                    'track_stock' => false,
                    'stock' => 0,
                    'is_active' => true,
                ]);
                if ($sku !== '') {
                    $existing[mb_strtolower($sku)] = true;
                }
                $room--;
                $result['created']++;
            }

            if ($result['created'] > 0) {
                $this->audit->record(
                    'product.import',
                    sprintf('Ambil produk dari Akuntansi: %d baru, %d dilewati.', $result['created'], $result['skipped']),
                    $user,
                    changes: $result,
                );
            }
        });

        return $result;
    }
}
