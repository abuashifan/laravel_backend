<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Services\PosSaleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosSaleController extends PosController
{
    public function __construct(private readonly PosSaleService $sales) {}

    public function index(Request $request): JsonResponse
    {
        $query = PosSale::query()->with('customer:id,name')->withCount('lines')->latest('sold_at');

        if ($request->filled('date_from')) {
            $query->where('sold_at', '>=', CarbonImmutable::parse((string) $request->query('date_from'))->startOfDay());
        }
        if ($request->filled('date_to')) {
            $query->where('sold_at', '<=', CarbonImmutable::parse((string) $request->query('date_to'))->endOfDay());
        }
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

    public function show(int $id): JsonResponse
    {
        return $this->successResponse(PosSale::query()->with('lines', 'customer')->findOrFail($id));
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
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $sale = $this->sales->create($this->user($request), $data);

        return $this->successResponse($sale, 'Transaksi tersimpan.', 201);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $sale = PosSale::query()->with('lines')->findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->successResponse($this->sales->void($sale, $this->user($request), $data['reason']), 'Transaksi dibatalkan.');
    }
}
