<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesActionRequest;
use App\Http\Requests\Sales\StoreSalesOrderRequest;
use App\Http\Requests\Sales\UpdateSalesOrderRequest;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesQuotation;
use App\Services\Sales\SalesOrderService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesOrderController extends Controller
{
    use ApiResponse;
    public function __construct(private readonly SalesOrderService $service) {}
    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Sales orders retrieved successfully'); }
    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (($data['source_type'] ?? null) === 'sales_quotation' && ! empty($data['source_id'])) {
            return $this->successResponse($this->service->createFromQuotation(SalesQuotation::query()->findOrFail((int) $data['source_id']), $data), 'Sales order created from quotation successfully', 201);
        }

        return $this->successResponse($this->service->create($data), 'Sales order created successfully', 201);
    }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Sales order retrieved successfully'); }
    public function update(UpdateSalesOrderRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->update(SalesOrder::query()->findOrFail($id), $request->validated()), 'Sales order updated successfully'); }
    public function createFromQuotation(Request $request, int $quotationId): JsonResponse { return $this->successResponse($this->service->createFromQuotation(SalesQuotation::query()->findOrFail($quotationId), $request->all()), 'Sales order created from quotation successfully', 201); }
    public function approve(int $id): JsonResponse { return $this->successResponse($this->service->approve(SalesOrder::query()->findOrFail($id)), 'Sales order approved successfully'); }
    public function confirm(int $id): JsonResponse { return $this->successResponse($this->service->confirm(SalesOrder::query()->findOrFail($id)), 'Sales order confirmed successfully'); }
    public function cancel(SalesActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->cancel(SalesOrder::query()->findOrFail($id), $request->validated('reason')), 'Sales order cancelled successfully'); }
    public function close(int $id): JsonResponse { return $this->successResponse($this->service->close(SalesOrder::query()->findOrFail($id)), 'Sales order closed successfully'); }
}
