<?php

namespace App\Modules\Access\Services;

use App\Shared\Auth\TokenAbility;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyInvitation;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Permission;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Permission\CompanyAccessService;
use App\Shared\Permission\WorkspaceMemberAccess;
use App\Shared\Subscription\UserQuotaService;
use Illuminate\Support\Facades\DB;

class InvitationAcceptanceService
{
    public function __construct(
        private readonly UserQuotaService $quota,
        private readonly CompanyAccessService $access,
        private readonly WorkspaceMemberAccess $workspace,
    ) {}

    /** Undangan yang masih bisa dipakai, atau galat INVITATION_INVALID. */
    public function findUsable(string $token): CompanyInvitation
    {
        $invitation = CompanyInvitation::query()->where('token', $token)->first();

        if (! $invitation || $invitation->status !== 'pending' || ! $invitation->expires_at || $invitation->expires_at->isPast()) {
            throw ApiException::make('INVITATION_INVALID', 'Invitation is no longer valid.', 422);
        }

        return $invitation;
    }

    public function accept(string $token, User $user): CompanyUser
    {
        return DB::transaction(function () use ($token, $user) {
            $invitation = CompanyInvitation::query()->where('token', $token)->firstOrFail();
            $company = Company::query()->whereKey($invitation->company_id)->lockForUpdate()->firstOrFail();
            $invitation = CompanyInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($invitation->token !== $token || $invitation->status !== 'pending' || ! $invitation->expires_at || $invitation->expires_at->isPast()) {
                throw ApiException::make('INVITATION_INVALID', 'Invitation is no longer valid.', 422);
            }
            if (strcasecmp($invitation->email, $user->email) !== 0) {
                throw ApiException::make('INVITATION_EMAIL_MISMATCH', 'Invitation belongs to another account.', 403);
            }
            $member = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->lockForUpdate()->first();
            if ($invitation->product === TenantDatabase::PRODUCT_WORKSPACE) {
                return $this->acceptWorkspace($invitation, $company, $member, $user);
            }

            if (TokenAbility::isWorkspaceOnly($user)) {
                throw ApiException::make('COMPANY_ACCESS_DENIED', 'Gunakan sesi produk yang sesuai untuk menerima undangan ini.', 403);
            }
            if ($member?->status === 'active') {
                throw ApiException::make('ALREADY_COMPANY_MEMBER', 'User is already an active member.', 422);
            }
            if (! $this->quota->canAddUser($company)) {
                throw ApiException::make('USER_QUOTA_EXCEEDED', 'Company user quota is full.', 422);
            }
            $before = $member ? $this->access->snapshot($member) : [];
            $member ??= new CompanyUser(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'viewer']);
            if ($member->is_owner) {
                throw ApiException::make('OWNER_PROTECTED', 'Owner access cannot be replaced by an invitation.', 422);
            }
            $member->forceFill(['status' => 'active', 'full_access' => $invitation->full_access, 'is_owner' => false, 'joined_at' => $member->joined_at ?? now()])->save();
            $member->assignedPermissions()->sync(Permission::query()->whereIn('key', $invitation->permissions ?? [])->pluck('id')->all());
            $invitation->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
            $this->access->record('access.invitation.accepted', $member, $user->id, $before, $this->access->snapshot($member), ['invitation_id' => $invitation->id]);

            return $member;
        });
    }

    private function acceptWorkspace(CompanyInvitation $invitation, Company $company, ?CompanyUser $member, User $user): CompanyUser
    {
        if ($user->is_platform_admin || $user->status !== 'active') {
            throw ApiException::make('COMPANY_ACCESS_DENIED', 'Akun ini tidak bisa menerima undangan Workspace.', 403);
        }
        $this->workspace->assertAvailable($company, $member, $user->email);
        $before = $member ? $this->access->snapshot($member) : [];
        $member ??= CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'viewer',
            'status' => 'active',
            'full_access' => false,
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        $permission = Permission::query()->where('key', WorkspaceMemberAccess::UNIT_VIEW)->firstOrFail();
        $member->assignedPermissions()->syncWithoutDetaching([$permission->id]);
        $invitation->update(['status' => 'accepted', 'accepted_at' => now()]);
        $this->access->record('access.invitation.accepted', $member, $user->id, $before, $this->access->snapshot($member), [
            'invitation_id' => $invitation->id,
            'product' => TenantDatabase::PRODUCT_WORKSPACE,
        ]);

        return $member;
    }
}
