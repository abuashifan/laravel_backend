<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreChartOfAccountRequest;
use App\Http\Requests\MasterData\UpdateChartOfAccountRequest;
use App\Models\Tenant\ChartOfAccount;
use App\Services\MasterData\ChartOfAccountService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChartOfAccountController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ChartOfAccountService $service) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->service->list($request->query());

        return $this->listResponse($items, $request, 'Chart of accounts retrieved successfully');
    }

    public function store(StoreChartOfAccountRequest $request): JsonResponse
    {
        $account = $this->service->create($request->validated());

        return $this->successResponse($account, 'Chart of account created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $account = ChartOfAccount::query()->with('parent')->findOrFail($id);

        return $this->successResponse($account, 'Chart of account retrieved successfully');
    }

    public function update(UpdateChartOfAccountRequest $request, int $id): JsonResponse
    {
        $account = ChartOfAccount::query()->findOrFail($id);
        $account = $this->service->update($account, $request->validated());

        return $this->successResponse($account, 'Chart of account updated successfully');
    }

    public function deactivate(int $id): JsonResponse
    {
        $account = ChartOfAccount::query()->findOrFail($id);
        $account = $this->service->deactivate($account);

        return $this->successResponse($account, 'Chart of account deactivated successfully');
    }

    public function activate(int $id): JsonResponse
    {
        $account = ChartOfAccount::query()->findOrFail($id);
        $account = $this->service->activate($account);

        return $this->successResponse($account, 'Chart of account activated successfully');
    }
}
