<?php

namespace App\Modules\Tenant\Controllers;

use App\Shared\Api\ApiResponse;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;

class TenantContextTestController
{
    use ApiResponse;

    public function __invoke(TenantContext $tenantContext): JsonResponse
    {
        return $this->successResponse([
            'company_id' => $tenantContext->companyId(),
            'company_name' => $tenantContext->company()?->name,
            'database_name' => $tenantContext->databaseName(),
            'database_path' => $tenantContext->databasePath(),
            'user_role' => $tenantContext->role(),
        ], 'Tenant context retrieved successfully');
    }
}
