<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesActionRequest;
use App\Http\Requests\Sales\StoreSalesReceiptRequest;
use App\Models\Tenant\SalesReceipt;
use App\Services\Sales\SalesReceiptService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesReceiptController extends Controller
{
    use ApiResponse;
    public function __construct(private readonly SalesReceiptService $service) {}
    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Sales receipts retrieved successfully'); }
    public function store(StoreSalesReceiptRequest $request): JsonResponse { return $this->successResponse($this->service->create($request->validated()), 'Sales receipt created successfully', 201); }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Sales receipt retrieved successfully'); }
    public function post(int $id): JsonResponse { return $this->successResponse($this->service->post(SalesReceipt::query()->findOrFail($id)), 'Sales receipt posted successfully'); }
    public function void(SalesActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->void(SalesReceipt::query()->findOrFail($id), $request->validated('reason')), 'Sales receipt voided successfully'); }
}
