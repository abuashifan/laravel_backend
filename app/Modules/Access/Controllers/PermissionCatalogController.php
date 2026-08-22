<?php

namespace App\Modules\Access\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiResponse;
use App\Shared\Models\Permission;
use App\Shared\Permission\PermissionCatalogService;
use App\Shared\Subscription\PlanPermissionResolver;
use App\Shared\Subscription\UpgradeLinkBuilder;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;

class PermissionCatalogController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PlanPermissionResolver $planPermissionResolver,
        private readonly UpgradeLinkBuilder $upgradeLinkBuilder,
    ) {}

    /**
     * Katalog izin lengkap, PLUS yang mana di antaranya terkunci paket
     * perusahaan aktif. Editor role (modul Access) menampilkan yang terkunci
     * dengan label "tidak termasuk paket" alih-alih menyembunyikannya — admin
     * perusahaan tetap tahu izin itu ada dan kenapa mati (Fase 2 skema tier).
     */
    public function __invoke(PermissionCatalogService $catalogService): JsonResponse
    {
        $company = $this->tenantContext->company();

        $blockedKeys = $company
            ? Permission::query()
                ->pluck('key')
                ->reject(fn (string $key): bool => $this->planPermissionResolver->allows($company, $key))
                ->values()
                ->all()
            : [];

        return $this->successResponse([
            ...$catalogService->grouped(),
            'blocked_by_plan_keys' => $blockedKeys,
            'upgrade_url' => $company ? $this->upgradeLinkBuilder->buildFor($company) : null,
        ], 'Permission catalog retrieved.');
    }
}
