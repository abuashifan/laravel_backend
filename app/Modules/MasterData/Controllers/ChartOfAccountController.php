<?php

namespace App\Modules\MasterData\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Requests\StoreChartOfAccountRequest;
use App\Modules\MasterData\Requests\UpdateChartOfAccountRequest;
use App\Modules\MasterData\Services\ChartOfAccountService;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ResolvesAdjacentRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChartOfAccountController extends Controller
{
    use ApiResponse;
    use ResolvesAdjacentRecords;

    public function __construct(private readonly ChartOfAccountService $service) {}

    public function adjacent(Request $request): JsonResponse
    {
        return $this->adjacentResponse(ChartOfAccount::query(), $request, 'account_code');
    }

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
