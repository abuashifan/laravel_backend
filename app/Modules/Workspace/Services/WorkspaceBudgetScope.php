<?php

namespace App\Modules\Workspace\Services;

use App\Modules\Workspace\Models\WorkspaceUnit;
use App\Modules\Workspace\Support\BudgetPermission;
use App\Shared\Exceptions\ApiException;
use App\Shared\Permission\PermissionService;
use App\Shared\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class WorkspaceBudgetScope
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionService $permissions,
    ) {}

    public function readableUnits(string $kind): Builder
    {
        $units = WorkspaceUnit::query();
        if ($this->permissions->can($this->approvalPermission($kind)) || $this->hasCompanyAccess()) {
            return $units;
        }

        return $this->assigned($units);
    }

    public function editableUnits(): Builder
    {
        $units = WorkspaceUnit::query();
        if (! $this->hasCompanyAccess()) {
            $this->assigned($units);
        }

        return $units;
    }

    public function assertActive(WorkspaceUnit $unit): void
    {
        if (! $unit->is_active) {
            throw ApiException::make('WORKSPACE_UNIT_INACTIVE', 'Unit nonaktif tidak dapat mengajukan atau menyetujui RAB.', 422);
        }
    }

    public function assertCanDecide(string $kind): void
    {
        abort_unless($this->permissions->can($this->approvalPermission($kind)), 403);
    }

    private function approvalPermission(string $kind): string
    {
        return $kind === 'annual' ? BudgetPermission::APPROVE_ANNUAL : BudgetPermission::APPROVE_ACTIVITY;
    }

    private function hasCompanyAccess(): bool
    {
        $member = $this->context->companyUser();

        return $member->is_owner || $member->full_access;
    }

    private function assigned(Builder $query): Builder
    {
        return $query->whereIn('id', DB::connection('workspace')->table('workspace_unit_members')
            ->where('company_user_id', $this->context->companyUser()->id)->select('unit_id'));
    }
}
