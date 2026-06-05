<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesActionRequest;
use App\Http\Requests\Sales\StoreSalesQuotationRequest;
use App\Http\Requests\Sales\UpdateSalesQuotationRequest;
use App\Models\Tenant\SalesQuotation;
use App\Services\Sales\SalesQuotationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesQuotationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SalesQuotationService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Sales quotations retrieved successfully');
    }

    public function store(StoreSalesQuotationRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->create($request->validated()), 'Sales quotation created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Sales quotation retrieved successfully');
    }

    public function update(UpdateSalesQuotationRequest $request, int $id): JsonResponse
    {
        $quotation = SalesQuotation::query()->findOrFail($id);
        return $this->successResponse($this->service->update($quotation, $request->validated()), 'Sales quotation updated successfully');
    }

    public function send(int $id): JsonResponse
    {
        return $this->successResponse($this->service->send(SalesQuotation::query()->findOrFail($id)), 'Sales quotation sent successfully');
    }

    public function approve(int $id): JsonResponse
    {
        return $this->successResponse($this->service->approve(SalesQuotation::query()->findOrFail($id)), 'Sales quotation approved successfully');
    }

    public function accept(int $id): JsonResponse
    {
        return $this->successResponse($this->service->accept(SalesQuotation::query()->findOrFail($id)), 'Sales quotation accepted successfully');
    }

    public function reject(SalesActionRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->reject(SalesQuotation::query()->findOrFail($id), $request->validated('reason')), 'Sales quotation rejected successfully');
    }

    public function cancel(SalesActionRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->cancel(SalesQuotation::query()->findOrFail($id), $request->validated('reason')), 'Sales quotation cancelled successfully');
    }
}
