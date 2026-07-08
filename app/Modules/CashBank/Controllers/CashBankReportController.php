<?php

namespace App\Modules\CashBank\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CashBank\Requests\CashBankAccountStatementRequest;
use App\Modules\CashBank\Services\CashBankReportService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class CashBankReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CashBankReportService $service) {}

    public function accountStatement(CashBankAccountStatementRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->service->accountStatement(
            (int) $data['cash_bank_account_id'],
            $data['start_date'] ?? null,
            $data['end_date'] ?? null,
        );

        return $this->successResponse($result, 'Cash/bank account statement retrieved successfully');
    }
}

