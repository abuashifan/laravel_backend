<?php

namespace App\Modules\Access\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiResponse;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyInvitation;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Permission;
use App\Shared\Permission\CompanyAccessService;
use App\Shared\Subscription\UserQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AcceptInvitationController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, string $token, UserQuotaService $quota, CompanyAccessService $access)
    {
        $member = DB::transaction(function () use ($request, $token, $quota, $access) {
            $invitation = CompanyInvitation::query()->where('token', $token)->firstOrFail();
            $company = Company::query()->whereKey($invitation->company_id)->lockForUpdate()->firstOrFail();
            $invitation = CompanyInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($invitation->token !== $token || $invitation->status !== 'pending' || ! $invitation->expires_at || $invitation->expires_at->isPast()) {
                throw ApiException::make('INVITATION_INVALID', 'Invitation is no longer valid.', 422);
            }
            if (strcasecmp($invitation->email, $request->user()->email) !== 0) {
                throw ApiException::make('INVITATION_EMAIL_MISMATCH', 'Invitation belongs to another account.', 403);
            }
            $member = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if ($member?->status === 'active') {
                throw ApiException::make('ALREADY_COMPANY_MEMBER', 'User is already an active member.', 422);
            }
            if (! $quota->canAddUser($company)) {
                throw ApiException::make('USER_QUOTA_EXCEEDED', 'Company user quota is full.', 422);
            }
            $before = $member ? $access->snapshot($member) : [];
            $member ??= new CompanyUser(['company_id' => $company->id, 'user_id' => $request->user()->id, 'role' => 'viewer']);
            if ($member->is_owner) {
                throw ApiException::make('OWNER_PROTECTED', 'Owner access cannot be replaced by an invitation.', 422);
            }
            $member->forceFill(['status' => 'active', 'full_access' => $invitation->full_access, 'is_owner' => false, 'joined_at' => $member->joined_at ?? now()])->save();
            $member->assignedPermissions()->sync(Permission::query()->whereIn('key', $invitation->permissions ?? [])->pluck('id')->all());
            $invitation->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
            $access->record('access.invitation.accepted', $member, $request->user()->id, $before, $access->snapshot($member), ['invitation_id' => $invitation->id]);

            return $member;
        });

        return $this->successResponse(['company_id' => $member->company_id, 'company_user_id' => $member->id], 'Invitation accepted.');
    }
}
