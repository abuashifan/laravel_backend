<?php

namespace App\Modules\Workspace\Services;

use App\Modules\Workspace\Models\WorkspaceUnit;
use App\Shared\Exceptions\ApiException;
use App\Shared\Permission\PermissionService;
use App\Shared\Permission\WorkspaceMemberAccess;
use App\Shared\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class WorkspaceUnitService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionService $permissions,
        private readonly WorkspaceMemberAccess $members,
    ) {}

    public function visible(): Builder
    {
        $units = WorkspaceUnit::query();
        if ($this->permissions->can('workspace.units.manage') || $this->permissions->can('workspace.members.manage')) {
            return $units;
        }

        return $units->where('is_active', true)->whereIn('id', DB::connection('workspace')->table('workspace_unit_members')
            ->where('company_user_id', $this->context->companyUser()->id)->select('unit_id'));
    }

    public function members(int $unit): array
    {
        WorkspaceUnit::query()->findOrFail($unit);
        $ids = DB::connection('workspace')->table('workspace_unit_members')->where('unit_id', $unit)->select('company_user_id')->pluck('company_user_id');

        return $this->members->members($this->context->company())->with('user:id,name,email')->whereIn('id', $ids)->orderBy('id')->get()
            ->map(fn ($member) => ['id' => $member->id, 'name' => $member->user->name, 'email' => $member->user->email])->all();
    }

    public function assign(int $unit, int $member): void
    {
        $this->members->members($this->context->company())->findOrFail($member);
        DB::connection('workspace')->transaction(function () use ($unit, $member): void {
            $record = WorkspaceUnit::query()->lockForUpdate()->findOrFail($unit);
            if (! $record->is_active) {
                throw ApiException::make('WORKSPACE_UNIT_INACTIVE', 'Unit nonaktif tidak menerima penempatan anggota.', 422);
            }

            DB::connection('workspace')->table('workspace_unit_members')->insertOrIgnore(['unit_id' => $unit, 'company_user_id' => $member]);
        });
    }

    public function remove(int $unit, int $member): void
    {
        WorkspaceUnit::query()->findOrFail($unit);
        DB::connection('workspace')->table('workspace_unit_members')->where('unit_id', $unit)->where('company_user_id', $member)->delete();
    }
}
