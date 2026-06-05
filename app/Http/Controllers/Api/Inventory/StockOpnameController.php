<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockOpnameActionRequest;
use App\Http\Requests\Inventory\StoreStockOpnameRequest;
use App\Http\Requests\Inventory\UpdateStockOpnameLineRequest;
use App\Http\Requests\Inventory\VoidStockOpnameRequest;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\StockOpnameLine;
use App\Services\Inventory\StockOpnameService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockOpnameController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StockOpnameService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Stock opnames retrieved successfully');
    }

    public function store(StoreStockOpnameRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->createSession($request->validated()), 'Stock opname created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Stock opname retrieved successfully');
    }

    public function generateLines(StockOpnameActionRequest $request, int $id): JsonResponse
    {
        $opname = StockOpname::query()->findOrFail($id);
        return $this->successResponse($this->service->generateLinesFromStockBalance($opname), 'Stock opname lines generated successfully');
    }

    public function updateLine(UpdateStockOpnameLineRequest $request, int $id, int $lineId): JsonResponse
    {
        $line = StockOpnameLine::query()->where('stock_opname_id', $id)->findOrFail($lineId);
        return $this->successResponse($this->service->updateLineCount($line, $request->validated()), 'Stock opname line updated successfully');
    }

    public function markCounted(StockOpnameActionRequest $request, int $id): JsonResponse
    {
        $opname = StockOpname::query()->findOrFail($id);
        return $this->successResponse($this->service->markCounted($opname), 'Stock opname marked counted successfully');
    }

    public function finalize(StockOpnameActionRequest $request, int $id): JsonResponse
    {
        $opname = StockOpname::query()->findOrFail($id);
        return $this->successResponse($this->service->finalize($opname), 'Stock opname finalized successfully');
    }

    public function void(VoidStockOpnameRequest $request, int $id): JsonResponse
    {
        $opname = StockOpname::query()->findOrFail($id);
        return $this->successResponse($this->service->void($opname, $request->validated('reason')), 'Stock opname voided successfully');
    }
}

