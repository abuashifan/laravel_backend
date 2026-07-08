<?php

namespace App\Modules\CashBank\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CashBank\Models\CashReceipt;
use App\Modules\CashBank\Requests\CashBankActionRequest;
use App\Modules\CashBank\Requests\StoreCashReceiptRequest;
use App\Modules\CashBank\Services\CashReceiptService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashReceiptController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CashReceiptService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Cash receipts retrieved successfully');
    }

    public function store(StoreCashReceiptRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->create($request->validated()), 'Cash receipt created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Cash receipt retrieved successfully');
    }

    public function post(int $id): JsonResponse
    {
        return $this->successResponse($this->service->post(CashReceipt::query()->findOrFail($id)), 'Cash receipt posted successfully');
    }

    public function void(CashBankActionRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->void(CashReceipt::query()->findOrFail($id), $request->validated('reason')), 'Cash receipt voided successfully');
    }
}
