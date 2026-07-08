<?php

namespace App\Modules\MasterData\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Requests\StorePaymentTermRequest;
use App\Modules\MasterData\Requests\UpdatePaymentTermRequest;
use App\Modules\MasterData\Services\PaymentTermService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentTermController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentTermService $service) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->service->list($request->query());

        return $this->listResponse($items, $request, 'Payment terms retrieved successfully');
    }

    public function store(StorePaymentTermRequest $request): JsonResponse
    {
        $paymentTerm = $this->service->create($request->validated());

        return $this->successResponse($paymentTerm, 'Payment term created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $paymentTerm = PaymentTerm::query()->findOrFail($id);

        return $this->successResponse($paymentTerm, 'Payment term retrieved successfully');
    }

    public function update(UpdatePaymentTermRequest $request, int $id): JsonResponse
    {
        $paymentTerm = PaymentTerm::query()->findOrFail($id);
        $paymentTerm = $this->service->update($paymentTerm, $request->validated());

        return $this->successResponse($paymentTerm, 'Payment term updated successfully');
    }

    public function deactivate(int $id): JsonResponse
    {
        $paymentTerm = PaymentTerm::query()->findOrFail($id);
        $paymentTerm = $this->service->deactivate($paymentTerm);

        return $this->successResponse($paymentTerm, 'Payment term deactivated successfully');
    }

    public function activate(int $id): JsonResponse
    {
        $paymentTerm = PaymentTerm::query()->findOrFail($id);
        $paymentTerm = $this->service->activate($paymentTerm);

        return $this->successResponse($paymentTerm, 'Payment term activated successfully');
    }
}
