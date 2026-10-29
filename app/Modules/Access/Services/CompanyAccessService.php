<?php

namespace App\Modules\Access\Services;

use App\Shared\Audit\AuditLogService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Permission;
use App\Shared\Permission\EffectivePermissionService;
use App\Shared\Subscription\UserQuotaService;
use Illuminate\Support\Facades\DB;

class CompanyAccessService
{
    public function __construct(private readonly EffectivePermissionService $effective, private readonly AuditLogService $audit, private readonly UserQuotaService $quota) {}

    public function snapshot(CompanyUser $member): array
    {
        return ['full_access' => (bool) $member->full_access, 'is_owner' => (bool) $member->is_owner, 'permissions' => $member->assignedPermissions()->pluck('permissions.key')->sort()->values()->all(), 'status' => $member->status];
    }

    public function update(CompanyUser $member, bool $fullAccess, array $keys, ?int $actor, string $action, array $metadata = []): void
    {
        DB::transaction(function () use ($member, $fullAccess, $keys, $actor, $action, $metadata): void {
            Company::query()->whereKey($member->company_id)->lockForUpdate()->firstOrFail();
            $member = CompanyUser::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($member);
            if ($member->is_owner && ! $fullAccess) {
                throw ApiException::make('OWNER_ACCESS_REQUIRED', 'The owner must retain full access.', 422);
            }
            if ($member->user_id === $actor) {
                $current = $this->effective->getEffectivePermissionKeys($member);
                if (($fullAccess && ! $member->full_access && ! $member->is_owner) || (! $fullAccess && array_diff($keys, $current))) {
                    throw ApiException::make('SELF_ACCESS_INCREASE_NOT_ALLOWED', 'Users cannot increase their own access.', 422);
                }
            }
            $member->forceFill(['full_access' => $fullAccess])->save();
            $member->assignedPermissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id')->all());
            $this->ensureManager($member->company_id);
            $this->record($action, $member, $actor, $before, $this->snapshot($member), $metadata);
        });
    }

    public function changeStatus(CompanyUser $member, string $status, int $actor): void
    {
        DB::transaction(function () use ($member, $status, $actor): void {
            $company = Company::query()->whereKey($member->company_id)->lockForUpdate()->firstOrFail();
            $member = CompanyUser::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($member);
            if ($status === 'active' && $member->status !== 'active' && ! $this->quota->canAddUser($company)) {
                throw ApiException::make('USER_QUOTA_EXCEEDED', 'Company user quota is full.', 422);
            }
            if ($member->is_owner && $status !== 'active') {
                throw ApiException::make('OWNER_PROTECTED', 'The owner cannot be deactivated or removed.', 422);
            }
            if ($member->user_id === $actor && $status !== 'active') {
                throw ApiException::make('SELF_ACCESS_CHANGE_NOT_ALLOWED', 'Users cannot deactivate or remove themselves.', 422);
            }
            $member->forceFill(['status' => $status])->save();
            $this->ensureManager($member->company_id);
            $this->record('access.user.'.$status, $member, $actor, $before, $this->snapshot($member));
        });
    }

    public function ensureManager(int $companyId): void
    {
        foreach (CompanyUser::query()->where('company_id', $companyId)->where('status', 'active')->get() as $member) {
            if ($this->effective->hasPermission($member, 'access.users.edit')) {
                return;
            }
        }
        throw ApiException::make('LAST_ACCESS_MANAGER_REQUIRED', 'At least one active user must be able to edit access.', 422);
    }

    public function record(string $action, CompanyUser $member, ?int $actor, array $before, array $after, array $metadata = []): void
    {
        $logged = $this->audit->logSuccess(['company_id' => $member->company_id, 'user_id' => $actor, 'event' => $action, 'action' => $action, 'module' => 'access', 'message' => 'Access management change.', 'record_type' => CompanyUser::class, 'record_id' => $member->id, 'old_values' => $before, 'new_values' => $after, 'metadata' => $metadata], tenant: false);
        if (! $logged) {
            throw new \RuntimeException('Unable to record access audit.');
        }
    }
}
