<?php

namespace App\Http\Controllers\Api\CashBank;

use App\Http\Controllers\Controller;
use App\Services\CashBank\CashBankAccountService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashBankAccountController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CashBankAccountService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $includeInactive = $request->boolean('include_inactive', false);

        $accounts = $this->service->getCashBankAccounts(includeInactive: $includeInactive);

        return $this->successResponse([
            'include_inactive' => $includeInactive,
            'accounts' => $accounts->map(fn ($a) => [
                'id' => (int) $a->id,
                'account_code' => (string) $a->account_code,
                'account_name' => (string) $a->account_name,
                'account_type' => (string) $a->account_type,
                'normal_balance' => (string) $a->normal_balance,
                'is_cash_bank' => (bool) $a->is_cash_bank,
                'is_active' => (bool) $a->is_active,
            ])->values()->all(),
        ], 'Cash/bank accounts retrieved successfully');
    }
}

