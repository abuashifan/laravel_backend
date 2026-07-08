<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchase\Models\PurchaseRequest;
use App\Modules\Purchase\Requests\PurchaseRequestActionRequest;
use App\Modules\Purchase\Requests\StorePurchaseRequestRequest;
use App\Modules\Purchase\Requests\UpdatePurchaseRequestRequest;
use App\Modules\Purchase\Services\PurchaseRequestService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PurchaseRequestService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Purchase requests retrieved successfully');
    }

    public function store(StorePurchaseRequestRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->create($request->validated()), 'Purchase request created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Purchase request retrieved successfully');
    }

    public function update(UpdatePurchaseRequestRequest $request, int $id): JsonResponse
    {
        $purchaseRequest = PurchaseRequest::query()->findOrFail($id);

        return $this->successResponse($this->service->update($purchaseRequest, $request->validated()), 'Purchase request updated successfully');
    }

    public function submit(int $id): JsonResponse
    {
        return $this->successResponse($this->service->submit(PurchaseRequest::query()->findOrFail($id)), 'Purchase request submitted successfully');
    }

    public function approve(int $id): JsonResponse
    {
        return $this->successResponse($this->service->approve(PurchaseRequest::query()->findOrFail($id)), 'Purchase request approved successfully');
    }

    public function reject(PurchaseRequestActionRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->reject(PurchaseRequest::query()->findOrFail($id), $request->validated('reason')), 'Purchase request rejected successfully');
    }

    public function cancel(PurchaseRequestActionRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->cancel(PurchaseRequest::query()->findOrFail($id), $request->validated('reason')), 'Purchase request cancelled successfully');
    }
}
