<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\UpdateAccountMappingRequest;
use App\Services\MasterData\AccountMappingStorageService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class AccountMappingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AccountMappingStorageService $service)
    {
    }

    public function index(): JsonResponse
    {
        $this->service->syncDefaultMappingsFromConfig();
        $items = $this->service->list();

        return $this->successResponse($items, 'Account mappings retrieved successfully');
    }

    public function update(UpdateAccountMappingRequest $request, string $mappingKey): JsonResponse
    {
        $mapping = $this->service->updateMapping($mappingKey, $request->validated()['account_id'] ?? null);

        return $this->successResponse($mapping, 'Account mapping updated successfully');
    }
}

