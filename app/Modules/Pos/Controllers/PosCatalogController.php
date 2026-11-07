<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Models\PosCategory;
use App\Modules\Pos\Models\PosProduct;
use App\Modules\Pos\Models\PosProductPhoto;
use App\Modules\Pos\Models\PosSaleLine;
use App\Modules\Pos\Models\PosStockMovement;
use App\Modules\Pos\Services\PosPlanService;
use App\Modules\Pos\Services\PosPricingService;
use App\Modules\Pos\Services\PosProductImportService;
use App\Modules\Pos\Services\PosProductPhotoService;
use App\Modules\Pos\Services\PosStockService;
use App\Shared\Export\ExcelExportService;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PosCatalogController extends PosController
{
    public function __construct(private readonly PosStockService $stock) {}

    // ---- Kategori ----

    public function categories(): JsonResponse
    {
        return $this->successResponse(PosCategory::query()->withCount('products')->orderBy('name')->get());
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('pos.pos_categories', 'name')]]);

        return $this->successResponse(PosCategory::query()->create($data), 'Kategori dibuat.', 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $category = PosCategory::query()->findOrFail($id);
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('pos.pos_categories', 'name')->ignore($id)]]);
        $category->update($data);

        return $this->successResponse($category, 'Kategori diperbarui.');
    }

    public function destroyCategory(int $id): JsonResponse
    {
        PosCategory::query()->findOrFail($id)->delete();

        return $this->successResponse(null, 'Kategori dihapus.');
    }

    // ---- Produk ----

    /**
     * Daftar produk untuk layar kasir dan halaman produk. `barcode` = cocok
     * persis (hasil scan), `search` = nama/SKU/barcode mengandung.
     */
    public function products(Request $request): JsonResponse
    {
        $query = PosProduct::query()->with('category:id,name')->orderBy('name');

        if ($barcode = trim((string) $request->query('barcode', ''))) {
            $query->where(fn ($q) => $q->where('barcode', $barcode)->orWhere('sku', $barcode));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereRaw(...$this->containsInsensitive('name', $search))
                    ->orWhereRaw(...$this->containsInsensitive('sku', $search))
                    ->orWhere('barcode', $search);
            });
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->query('category_id'));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        if (filter_var($request->query('low_stock', false), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('track_stock', true)->where('stock', '<=', 0);
        }

        return $this->paginated($query->paginate($this->perPage($request, 50)));
    }

    public function showProduct(int $id): JsonResponse
    {
        return $this->successResponse(PosProduct::query()->with('category:id,name')->findOrFail($id));
    }

    public function storeProduct(Request $request, PosPlanService $plans, TenantContext $context): JsonResponse
    {
        $data = $request->validate($this->productRules() + [
            'initial_stock' => ['nullable', 'numeric', 'min:0'],
        ]);
        if ($company = $context->company()) {
            $plans->assertCanAddProduct($company);
        }
        $initialStock = (float) ($data['initial_stock'] ?? 0);
        unset($data['initial_stock']);
        $data = $this->withTiers($data);

        $product = DB::connection('pos')->transaction(function () use ($data, $initialStock, $request) {
            $product = PosProduct::query()->create(array_merge($data, ['stock' => 0]));
            if ($product->track_stock && $initialStock > 0) {
                $this->stock->move($product, $initialStock, 'adjustment', null, null, 'Stok awal', $request->user()?->id);
            }

            return $product;
        });

        return $this->successResponse($product->refresh()->load('category:id,name'), 'Produk dibuat.', 201);
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $product = PosProduct::query()->findOrFail($id);
        $product->update($this->withTiers($request->validate($this->productRules($id, partial: true))));

        return $this->successResponse($product->refresh()->load('category:id,name'), 'Produk diperbarui.');
    }

    /** Produk yang pernah terjual hanya dinonaktifkan supaya riwayat struk tetap utuh. */
    public function destroyProduct(int $id): JsonResponse
    {
        $product = PosProduct::query()->findOrFail($id);

        if (PosSaleLine::query()->where('product_id', $id)->exists()) {
            $product->update(['is_active' => false]);

            return $this->successResponse($product, 'Produk sudah pernah terjual, jadi dinonaktifkan.');
        }

        $product->delete();

        return $this->successResponse(null, 'Produk dihapus.');
    }

    // ---- Foto produk ----

    public function photo(int $id, PosProductPhotoService $photos): JsonResponse
    {
        PosProduct::query()->findOrFail($id);

        return $this->successResponse([
            'photo' => PosProductPhoto::query()->where('product_id', $id)->value('data'),
        ] + $photos->usage());
    }

    /** Foto (sisi panjang ~480px) dan gambar mini (~96px) sebagai data URL, sudah diperkecil di aplikasi. */
    public function storePhoto(Request $request, int $id, PosProductPhotoService $photos): JsonResponse
    {
        $product = PosProduct::query()->findOrFail($id);
        $data = $request->validate([
            'photo' => ['required', 'string', 'max:'.(int) (PosProductPhotoService::MAX_PHOTO_BYTES * 1.4)],
            'thumb' => ['required', 'string', 'max:'.(int) (PosProductPhotoService::MAX_THUMB_BYTES * 1.4)],
        ]);

        $photos->store($product, $data['photo'], $data['thumb']);

        return $this->successResponse($product->refresh()->load('category:id,name'), 'Foto produk disimpan.');
    }

    public function destroyPhoto(int $id, PosProductPhotoService $photos): JsonResponse
    {
        $product = PosProduct::query()->findOrFail($id);
        $photos->delete($product);

        return $this->successResponse($product->refresh()->load('category:id,name'), 'Foto produk dihapus.');
    }

    // ---- Impor & ekspor ----

    /**
     * `commit` false = pratinjau (tidak menyimpan apa pun), true = simpan baris
     * yang benar dan lewati baris yang salah.
     */
    public function importProducts(Request $request, PosProductImportService $import, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'commit' => ['nullable', 'boolean'],
        ]);
        $file = $data['file'];
        $commit = (bool) ($data['commit'] ?? false);

        $result = $import->run(
            (string) $file->getRealPath(),
            (string) $file->getClientOriginalExtension(),
            $commit,
            $context->company(),
            $request->user()?->id,
        );

        return $this->successResponse($result, $commit ? 'Impor produk selesai.' : 'Pratinjau impor.');
    }

    /** Ekspor produk dengan kolom yang sama seperti templat impor (ubah di Excel, impor lagi). */
    public function exportProducts(Request $request, ExcelExportService $excel): StreamedResponse
    {
        $query = PosProduct::query()->with('category:id,name')->orderBy('name');
        if (filter_var($request->query('template', false), FILTER_VALIDATE_BOOLEAN)) {
            $query->whereRaw('1 = 0');
        }

        return $excel->download(
            $query,
            array_keys(PosProductImportService::COLUMNS),
            fn (PosProduct $product) => [
                $product->name,
                $product->sku,
                $product->barcode,
                $product->category?->name,
                $product->unit,
                $product->price,
                $product->cost,
                $product->track_stock ? $product->stock : null,
            ],
            'produk-kasir',
        );
    }

    /** Ubah stok: `set_stock` (hasil hitung fisik) atau `quantity` (+ restock / - koreksi). */
    public function adjustStock(Request $request, int $id): JsonResponse
    {
        $product = PosProduct::query()->findOrFail($id);
        $data = $request->validate([
            'set_stock' => ['required_without:quantity', 'nullable', 'numeric', 'min:0'],
            'quantity' => ['required_without:set_stock', 'nullable', 'numeric', 'not_in:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $product->track_stock) {
            return $this->validationErrorResponse(['product' => ['Stok produk ini tidak dilacak. Aktifkan "Lacak stok" dulu.']]);
        }

        DB::connection('pos')->transaction(function () use ($product, $data, $request) {
            $delta = isset($data['set_stock'])
                ? (float) $data['set_stock'] - (float) $product->stock
                : (float) $data['quantity'];
            $type = isset($data['set_stock']) ? 'adjustment' : ($delta > 0 ? 'restock' : 'adjustment');

            $this->stock->move($product, $delta, $type, null, null, $data['notes'] ?? null, $request->user()?->id);
        });

        return $this->successResponse($product->refresh(), 'Stok diperbarui.');
    }

    public function stockMovements(Request $request, int $id): JsonResponse
    {
        PosProduct::query()->findOrFail($id);

        return $this->paginated(
            PosStockMovement::query()->where('product_id', $id)->latest('id')->paginate($this->perPage($request))
        );
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function withTiers(array $data): array
    {
        if (array_key_exists('price_tiers', $data)) {
            $tiers = PosPricingService::normalizeTiers($data['price_tiers']);
            $data['price_tiers'] = $tiers === [] ? null : $tiers;
        }

        return $data;
    }

    /** @return array<string, array<int, mixed>> */
    private function productRules(?int $id = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:150'],
            'sku' => ['nullable', 'string', 'max:50', Rule::unique('pos.pos_products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('pos.pos_products', 'barcode')->ignore($id)],
            'category_id' => ['nullable', 'integer', 'exists:pos.pos_categories,id'],
            'unit' => ['nullable', 'string', 'max:20'],
            'price' => [$required, 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'track_stock' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            // Harga grosir: beli minimal sekian, harga satuan jadi sekian.
            'price_tiers' => ['nullable', 'array', 'max:5'],
            'price_tiers.*.min_quantity' => ['required', 'numeric', 'gt:1'],
            'price_tiers.*.price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
