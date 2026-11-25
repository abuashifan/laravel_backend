<?php

namespace App\Modules\Workspace\Services;

use App\Modules\Workspace\Support\BudgetPermission;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Permission\CompanyAccessService;
use App\Shared\Permission\WorkspaceMemberAccess;
use App\Shared\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

class WorkspaceBudgetAccessService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly WorkspaceMemberAccess $members,
        private readonly CompanyAccessService $access,
    ) {}

    public function update(int $memberId, array $permissions, int $actor): array
    {
        return DB::transaction(function () use ($memberId, $permissions, $actor) {
            $company = Company::query()->lockForUpdate()->findOrFail($this->context->companyId());
            $member = $this->members->members($company)->lockForUpdate()->findOrFail($memberId);
            if ($member->is_owner || $member->full_access) {
                throw ApiException::make('WORKSPACE_BUDGET_FULL_ACCESS', 'Anggota dengan akses penuh sudah memiliki semua izin RAB.', 422);
            }
            $keys = $member->assignedPermissions()->pluck('permissions.key')->all();
            $this->access->update(
                $member,
                false,
                array_merge(array_diff($keys, BudgetPermission::GRANTABLE), $permissions),
                $actor,
                'workspace.budget.access_updated',
            );

            return $permissions;
        }, 3);
    }
}
