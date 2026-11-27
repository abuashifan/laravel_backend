<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Models\PosGudangReceipt;
use App\Modules\Pos\Services\PosGudangReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Kotak masuk kiriman dari Finlite Gudang. */
class PosGudangReceiptController extends PosController
{
    public function __construct(private readonly PosGudangReceiptService $receipts) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['sometimes', Rule::in(PosGudangReceipt::STATUSES)]]);
        $query = PosGudangReceipt::query()->withCount('lines')->latest('shipped_at')->latest('id');
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return $this->paginated($query->paginate($this->perPage($request, 30)));
    }

    /** Jumlah kiriman yang menunggu, untuk lencana di menu. */
    public function summary(): JsonResponse
    {
        return $this->successResponse(['pending' => PosGudangReceipt::query()->where('status', 'pending')->count()]);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse(PosGudangReceipt::query()->with('lines')->findOrFail($id));
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.actual_quantity' => ['required', 'numeric', 'min:0', 'max:99999999', 'regex:/^\d+(\.\d{1,3})?$/'],
        ]);
        $receipt = $this->receipts->receive($this->user($request), $id, $data['lines'], $data['note'] ?? null);

        return $this->successResponse($receipt->load('lines'), 'Kiriman Gudang dikonfirmasi.');
    }
}
