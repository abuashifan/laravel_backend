<?php

namespace App\Modules\Access\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Requests\CopyAccessRequest;
use App\Modules\Access\Requests\UpdateCompanyUserPermissionRequest;
use App\Shared\Api\ApiResponse;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Permission;
use App\Shared\Permission\CompanyAccessService;
use App\Shared\Permission\EffectivePermissionService;
use App\Shared\Permission\PermissionCatalogService;
use App\Shared\Subscription\PlanPermissionResolver;
use App\Shared\Subscription\UpgradeLinkBuilder;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyUserAccessController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenant, private readonly CompanyAccessService $access, private readonly EffectivePermissionService $effective, private readonly PermissionCatalogService $catalog, private readonly PlanPermissionResolver $plan, private readonly UpgradeLinkBuilder $upgrade) {}

    public function index(): JsonResponse
    {
        return $this->successResponse($this->query()->get()->map(fn ($member) => $this->payload($member))->all(), 'Company users retrieved.');
    }

    public function show(int $companyUserId): JsonResponse
    {
        return $this->successResponse($this->payload($this->member($companyUserId)), 'Company user retrieved.');
    }

    public function permissions(int $companyUserId): JsonResponse
    {
        return $this->successResponse($this->permissionPayload($this->member($companyUserId)), 'User permissions retrieved.');
    }

    public function updatePermissions(UpdateCompanyUserPermissionRequest $request, int $companyUserId): JsonResponse
    {
        $member = $this->member($companyUserId);
        $data = $request->validated();
        $this->access->update($member, $data['full_access'], $data['permissions'], $request->user()->id, 'access.permissions.update');

        return $this->successResponse($this->permissionPayload($member->refresh()), 'User permissions updated.');
    }

    public function copyAccess(CopyAccessRequest $request, int $companyUserId): JsonResponse
    {
        $target = $this->member($companyUserId);
        $source = $this->member((int) $request->validated('source_company_user_id'));
        $sourceAccess = $this->access->snapshot($source);
        $this->access->update($target, $sourceAccess['full_access'] || $sourceAccess['is_owner'], $sourceAccess['permissions'], $request->user()->id, 'access.permissions.copy', ['source_company_user_id' => $source->id]);

        return $this->successResponse($this->permissionPayload($target->refresh()), 'Access copied.');
    }

    public function deactivate(Request $request, int $companyUserId): JsonResponse
    {
        return $this->status($request, $companyUserId, 'inactive');
    }

    public function activate(Request $request, int $companyUserId): JsonResponse
    {
        return $this->status($request, $companyUserId, 'active');
    }

    public function remove(Request $request, int $companyUserId): JsonResponse
    {
        return $this->status($request, $companyUserId, 'removed');
    }

    private function status(Request $request, int $id, string $status): JsonResponse
    {
        $member = $this->member($id);
        $this->access->changeStatus($member, $status, $request->user()->id);

        return $this->successResponse($this->payload($member->refresh()), 'Company user updated.');
    }

    private function query()
    {
        return CompanyUser::query()->with('user:id,name,email,status')->where('company_id', $this->tenant->companyId());
    }

    private function member(int $id): CompanyUser
    {
        return $this->query()->whereKey($id)->firstOrFail();
    }

    private function payload(CompanyUser $member): array
    {
        return ['id' => $member->id, 'company_id' => $member->company_id, 'user_id' => $member->user_id, 'name' => $member->user?->name, 'email' => $member->user?->email, 'status' => $member->status, 'joined_at' => $member->joined_at, 'full_access' => $member->full_access, 'is_owner' => $member->is_owner, 'feature_count' => $member->assignedPermissions()->whereIn('permissions.key', config('permissions.matrix_keys', []))->distinct()->count('permissions.feature')];
    }

    private function permissionPayload(CompanyUser $member): array
    {
        $company = $this->tenant->company();
        $blocked = Permission::query()->whereIn('key', config('permissions.matrix_keys', []))->pluck('key')->reject(fn ($key) => $this->plan->allows($company, $key))->values()->all();

        return [...$this->access->snapshot($member), 'company_user' => $this->payload($member), 'effective_permissions' => $this->effective->getEffectivePermissionKeys($member), 'catalog' => [...$this->catalog->grouped(), 'blocked_by_plan_keys' => $blocked, 'upgrade_url' => $this->upgrade->buildFor($company)]];
    }
}
