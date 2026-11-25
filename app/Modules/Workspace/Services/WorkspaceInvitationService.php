<?php

namespace App\Modules\Workspace\Services;

use App\Mail\CompanyInvitationMail;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyInvitation;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Permission\WorkspaceMemberAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class WorkspaceInvitationService
{
    public function __construct(private readonly WorkspaceMemberAccess $seats) {}

    public function invite(Company $company, User $actor, string $email): array
    {
        $email = mb_strtolower(trim($email));
        $invitation = DB::transaction(function () use ($company, $actor, $email): CompanyInvitation {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $member = CompanyUser::query()->where('company_id', $company->id)
                ->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))->first();
            $this->seats->assertAvailable($company, $member, $email);

            $invitation = CompanyInvitation::query()->where('company_id', $company->id)
                ->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($invitation?->status === 'pending' && $invitation->expires_at?->isFuture()
                && $invitation->product !== TenantDatabase::PRODUCT_WORKSPACE) {
                throw ApiException::make('ACTIVE_INVITATION_EXISTS', 'Email ini sudah punya undangan aktif dari produk Finlite lain.', 422);
            }

            $invitation ??= new CompanyInvitation(['company_id' => $company->id, 'email' => $email]);
            $invitation->fill([
                'product' => TenantDatabase::PRODUCT_WORKSPACE,
                'full_access' => false,
                'permissions' => [WorkspaceMemberAccess::UNIT_VIEW],
                'token' => Str::random(48),
                'status' => 'pending',
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
            ])->save();

            return $invitation;
        });

        $delivered = true;
        try {
            Mail::to($invitation->email)->send(new CompanyInvitationMail($invitation, $company->name, $actor->name));
        } catch (Throwable $exception) {
            report($exception);
            $delivered = false;
        }

        return [
            'email' => $invitation->email,
            'accept_url' => CompanyInvitationMail::acceptUrl($invitation),
            'mail_sent' => $delivered,
        ];
    }

    public function revoke(Company $company, int $id): void
    {
        DB::transaction(function () use ($company, $id): void {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $invitation = CompanyInvitation::query()->where('company_id', $company->id)
                ->where('product', TenantDatabase::PRODUCT_WORKSPACE)->findOrFail($id);
            if ($invitation->status !== 'pending') {
                throw ApiException::make('INVITATION_INVALID', 'Undangan ini sudah tidak menunggu.', 422);
            }
            $invitation->update(['status' => 'revoked']);
        });
    }
}
