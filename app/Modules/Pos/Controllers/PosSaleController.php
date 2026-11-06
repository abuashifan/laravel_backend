<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleReturn;
use App\Modules\Pos\Services\PosReturnService;
use App\Modules\Pos\Services\PosSaleService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosSaleController extends PosController
{
    public function __construct(
        private readonly PosSaleService $sales,
        private readonly PosReturnService $returns,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->dated(PosSale::query()->with('customer:id,name')->withCount('lines')->latest('sold_at'), $request, 'sold_at');
        foreach (['status', 'payment_method', 'shift_id', 'customer_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->query($filter));
            }
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->whereRaw(...$this->containsInsensitive('number', $search));
        }

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    /** Detail transaksi beserta retur-nya dan sisa jumlah yang masih bisa diretur per baris. */
    public function show(int $id): JsonResponse
    {
        $sale = PosSale::query()->with('lines', 'customer', 'returns.lines')->findOrFail($id);
        $sale->setAttribute('returnable', $this->returns->returnable($sale));

        return $this->successResponse($sale);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'payment_method' => ['required', Rule::in(PosSaleService::METHODS)],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'receipt_mode' => ['nullable', Rule::in(PosSaleService::RECEIPT_MODES)],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Antrean ulang aplikasi kasir: kunci yang sama = transaksi yang sudah tersimpan.
        if (! empty($data['idempotency_key']) && ($existing = $this->byKey((string) $data['idempotency_key']))) {
            return $this->successResponse($existing, 'Transaksi sudah tersimpan.');
        }

        try {
            $sale = $this->sales->create($this->user($request), $data);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = ! empty($data['idempotency_key']) ? $this->byKey((string) $data['idempotency_key']) : null;
            if (! $existing) {
                throw $exception;
            }

            return $this->successResponse($existing, 'Transaksi sudah tersimpan.');
        }

        return $this->successResponse($sale, 'Transaksi tersimpan.', 201);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $sale = PosSale::query()->with('lines')->findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->successResponse($this->sales->void($sale, $this->user($request), $data['reason']), 'Transaksi dibatalkan.');
    }

    public function storeReturn(Request $request, int $id): JsonResponse
    {
        $sale = PosSale::query()->with('lines')->findOrFail($id);
        $data = $request->validate([
            'refund_method' => ['nullable', Rule::in(PosReturnService::REFUND_METHODS)],
            'reason' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.sale_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.restock' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse($this->returns->create($sale, $this->user($request), $data), 'Retur tersimpan.', 201);
    }

    public function returns(Request $request): JsonResponse
    {
        $query = $this->dated(PosSaleReturn::query()->with('sale:id,number')->withCount('lines')->latest('returned_at'), $request, 'returned_at');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    private function byKey(string $key): ?PosSale
    {
        return PosSale::query()->with('lines', 'customer')->where('idempotency_key', $key)->first();
    }
}
