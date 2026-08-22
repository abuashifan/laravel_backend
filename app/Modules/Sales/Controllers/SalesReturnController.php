<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Requests\SalesActionRequest;
use App\Modules\Sales\Requests\StoreSalesReturnRequest;
use App\Modules\Sales\Requests\UpdateSalesReturnRequest;
use App\Modules\Sales\Services\SalesReturnService;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ResolvesAdjacentRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesReturnController extends Controller
{
    use ApiResponse;
    use ResolvesAdjacentRecords;

    public function __construct(private readonly SalesReturnService $service) {}

    public function adjacent(Request $request): JsonResponse
    {
        return $this->adjacentResponse(SalesReturn::query(), $request, 'return_number');
    }

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Sales returns retrieved successfully');
    }

    public function store(StoreSalesReturnRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->create($request->validated()), 'Sales return created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Sales return retrieved successfully');
    }

    public function update(UpdateSalesReturnRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->update(SalesReturn::query()->findOrFail($id), $request->validated()), 'Sales return updated successfully');
    }

    public function createFromSalesInvoice(Request $request, int $invoiceId): JsonResponse
    {
        return $this->successResponse($this->service->createFromSalesInvoice(SalesInvoice::query()->findOrFail($invoiceId), $request->all()), 'Sales return created from invoice successfully', 201);
    }

    public function createFromDeliveryOrder(Request $request, int $deliveryOrderId): JsonResponse
    {
        return $this->successResponse($this->service->createFromDeliveryOrder(DeliveryOrder::query()->findOrFail($deliveryOrderId), $request->all()), 'Sales return created from delivery order successfully', 201);
    }

    public function approve(int $id): JsonResponse
    {
        return $this->successResponse($this->service->approve(SalesReturn::query()->findOrFail($id)), 'Sales return approved successfully');
    }

    public function post(int $id): JsonResponse
    {
        return $this->successResponse($this->service->post(SalesReturn::query()->findOrFail($id)), 'Sales return posted successfully');
    }

    public function void(SalesActionRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->void(SalesReturn::query()->findOrFail($id), $request->validated('reason')), 'Sales return voided successfully');
    }
}
