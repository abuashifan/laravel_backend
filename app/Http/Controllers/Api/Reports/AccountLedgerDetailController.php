<?php

namespace App\Http\Controllers\Api\Reports;

use App\Data\Reports\AccountLedgerFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\AccountLedgerDetailRequest;
use App\Services\Reports\AccountLedgerDetailService;
use App\Support\Api\ApiResponseBuilder;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class AccountLedgerDetailController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AccountLedgerDetailService $service)
    {
    }

    public function show(AccountLedgerDetailRequest $request, int|string $account): JsonResponse
    {
        $accountId = (int) $account;
        if ($accountId <= 0) {
            return ApiResponseBuilder::error('ACCOUNT_NOT_FOUND', 'Account not found.', [], 404);
        }

        $filter = AccountLedgerFilter::fromArray($accountId, $request->validated());

        $result = $this->service->getDetail($accountId, $filter);
        if (! ($result['valid'] ?? false)) {
            $status = (int) ($result['status'] ?? 422);
            $errors = (array) ($result['errors'] ?? []);

            if ($status === 404) {
                return ApiResponseBuilder::error('ACCOUNT_NOT_FOUND', 'Account not found.', $errors, 404, [
                    'filter' => $filter->toArray(),
                ]);
            }

            return ApiResponseBuilder::validation($errors, 'Invalid account ledger filter.', [
                'filter' => $filter->toArray(),
            ]);
        }

        return $this->successResponse($result, 'Account ledger retrieved successfully');
    }
}

