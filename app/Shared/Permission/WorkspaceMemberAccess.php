<?php

namespace App\Shared\Permission;

use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyInvitation;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Subscription\PlanOwnerResolver;
use App\Shared\Subscription\SubscriptionService;
use App\Shared\Tenant\TenantProductService;
use Illuminate\Database\Eloquent\Builder;

class WorkspaceMemberAccess
{
    public const UNIT_VIEW = 'workspace.units.view';

    public function __construct(
        private readonly PlanOwnerResolver $owners,
        private readonly SubscriptionService $subscriptions,
        private readonly TenantProductService $products,
    ) {}

    public function members(Company $company): Builder
    {
        return CompanyUser::query()->where('company_id', $company->id)->where('status', 'active')
            ->where(fn (Builder $query) => $query->where('is_owner', true)->orWhere('full_access', true)
                ->orWhereHas('assignedPermissions', fn (Builder $permissions) => $permissions->where('permissions.key', 'like', 'workspace.%')));
    }

    public function assertAvailable(Company $company, ?CompanyUser $member, string $email): void
    {
        $owner = $this->owners->ownerOf($company);
        $active = $this->products->find($company, TenantDatabase::PRODUCT_WORKSPACE)?->status === 'active';
        if (! $active || ! $owner || ! $this->subscriptions->hasAccess($owner, TenantDatabase::PRODUCT_WORKSPACE)) {
            throw ApiException::make('SUBSCRIPTION_INACTIVE', 'Langganan Workspace tidak aktif.', 422);
        }
        if ($member && $member->status !== 'active') {
            throw ApiException::make('WORKSPACE_MEMBER_INACTIVE', 'Aktifkan kembali anggota perusahaan sebelum memberi akses Workspace.', 422);
        }
        if ($member && $this->members($company)->whereKey($member->id)->exists()) {
            throw ApiException::make('WORKSPACE_MEMBER_EXISTS', 'Anggota ini sudah punya akses Workspace.', 422);
        }

        $limit = $this->subscriptions->planFor($owner, TenantDatabase::PRODUCT_WORKSPACE)?->max_users;
        $pending = CompanyInvitation::query()->where('company_id', $company->id)
            ->where('product', TenantDatabase::PRODUCT_WORKSPACE)
            ->where('status', 'pending')->where('expires_at', '>', now())
            ->whereRaw('LOWER(email) <> ?', [mb_strtolower($email)])
            ->count();
        if ($limit !== null && $this->members($company)->count() + $pending >= $limit) {
            throw ApiException::make('USER_QUOTA_EXCEEDED', 'Jatah pengguna Workspace sudah penuh.', 422);
        }
    }
}
