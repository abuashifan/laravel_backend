<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Models\PosPurchase;
use App\Modules\Pos\Models\PosStockCount;
use App\Modules\Pos\Models\PosSupplier;
use App\Modules\Pos\Models\PosSupplierPayment;
use App\Modules\Pos\Services\PosBridgeService;
use App\Modules\Pos\Services\PosPurchaseService;
use App\Modules\Pos\Services\PosStockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pemasok, stok masuk, dan stok opname (Fase 3). */
class PosInventoryController extends PosController
{
    public function __construct(
        private readonly PosPurchaseService $purchases,
        private readonly PosStockCountService $counts,
        private readonly PosBridgeService $bridge,
    ) {}

    // ---- Pemasok ----

    public function suppliers(Request $request): JsonResponse
    {
        $query = PosSupplier::query()->orderBy('name');
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q->whereRaw(...$this->containsInsensitive('name', $search))->orWhere('phone', 'like', "%{$search}%"));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        if (filter_var($request->query('has_payable', false), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('payable_balance', '>', 0);
        }

        return $this->paginated($query->paginate($this->perPage($request, 50)));
    }

    public function showSupplier(int $id): JsonResponse
    {
        $supplier = PosSupplier::query()->findOrFail($id);
        $supplier->setAttribute('purchases', PosPurchase::query()->where('supplier_id', $id)->latest('received_at')->limit(20)->get());
        $supplier->setAttribute('payments', PosSupplierPayment::query()->where('supplier_id', $id)->latest('paid_at')->limit(20)->get());

        return $this->successResponse($supplier);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        return $this->successResponse(PosSupplier::query()->create($request->validate($this->supplierRules())), 'Pemasok dibuat.', 201);
    }

    public function updateSupplier(Request $request, int $id): JsonResponse
    {
        $supplier = PosSupplier::query()->findOrFail($id);
        $supplier->update($request->validate($this->supplierRules(partial: true)));

        return $this->successResponse($supplier->refresh(), 'Pemasok diperbarui.');
    }

    /** Pemasok yang punya riwayat hanya dinonaktifkan. */
    public function destroySupplier(int $id): JsonResponse
    {
        $supplier = PosSupplier::query()->findOrFail($id);
        if ((float) $supplier->payable_balance > 0) {
            return $this->validationErrorResponse(['supplier' => ['Masih ada hutang ke pemasok ini.']]);
        }
        if (PosPurchase::query()->where('supplier_id', $id)->exists() || PosSupplierPayment::query()->where('supplier_id', $id)->exists()) {
            $supplier->update(['is_active' => false]);

            return $this->successResponse($supplier, 'Pemasok sudah punya riwayat, jadi dinonaktifkan.');
        }
        $supplier->delete();

        return $this->successResponse(null, 'Pemasok dihapus.');
    }

    public function paySupplier(Request $request, int $id): JsonResponse
    {
        $supplier = PosSupplier::query()->findOrFail($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(PosPurchaseService::METHODS)],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = $this->purchases->pay($supplier, $this->user($request), (float) $data['amount'], $data['method'], $data['notes'] ?? null);
        // Jembatan ke Akuntansi (bila menyala dan HPP ikut dikirim); tidak pernah menggagalkan pembayaran.
        $this->bridge->onSupplierPayment($payment);

        return $this->successResponse(['payment' => $payment, 'supplier' => $supplier], 'Pembayaran hutang tersimpan.', 201);
    }

    // ---- Stok masuk ----

    public function purchases(Request $request): JsonResponse
    {
        $query = $this->dated(PosPurchase::query()->with('supplier:id,name')->withCount('lines')->latest('received_at'), $request, 'received_at');
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int) $request->query('supplier_id'));
        }

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function showPurchase(int $id): JsonResponse
    {
        return $this->successResponse(PosPurchase::query()->with('lines', 'supplier')->findOrFail($id));
    }

    public function storePurchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(PosPurchaseService::METHODS)],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $purchase = $this->purchases->create($this->user($request), $data);
        $this->bridge->onPurchaseCreated($purchase);

        return $this->successResponse($purchase, 'Stok masuk tersimpan.', 201);
    }

    // ---- Stok opname ----

    public function stockCounts(Request $request): JsonResponse
    {
        $query = $this->dated(PosStockCount::query()->withCount('lines')->latest('counted_at'), $request, 'counted_at');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function showStockCount(int $id): JsonResponse
    {
        return $this->successResponse(PosStockCount::query()->with('lines')->findOrFail($id));
    }

    public function storeStockCount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.counted_quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $count = $this->counts->create($this->user($request), $data);
        $this->bridge->onStockCountCreated($count);

        return $this->successResponse($count, 'Stok opname tersimpan.', 201);
    }

    /** @return array<string, array<int, string>> */
    private function supplierRules(bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
