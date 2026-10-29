<?php

namespace App\Modules\Access\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Requests\InviteCompanyUserRequest;
use App\Shared\Api\ApiResponse;
use App\Shared\Audit\AuditLogService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyInvitation;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompanyInvitationAccessController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(): JsonResponse
    {
        $invitations = CompanyInvitation::query()
            ->where('company_id', $this->tenantContext->companyId())
            ->orderByDesc('created_at')
            ->get()
            ->each(function (CompanyInvitation $invitation): void {
                if ($invitation->status === 'pending' && $invitation->expires_at?->isPast()) {
                    $invitation->status = 'expired';
                }
            });

        return $this->successResponse($invitations, 'Invitations retrieved.');
    }

    public function store(InviteCompanyUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $invitation = DB::transaction(function () use ($data, $request) {
            Company::query()->whereKey($this->tenantContext->companyId())->lockForUpdate()->firstOrFail();
            $existing = CompanyInvitation::query()
                ->where('company_id', $this->tenantContext->companyId())
                ->where('email', $data['email'])
                ->first();

            if ($existing && $existing->status === 'pending' && ! $existing->expires_at?->isPast()) {
                throw ApiException::make('ACTIVE_INVITATION_EXISTS', 'An active invitation already exists for this email.', 422);
            }

            $before = $existing ? ['status' => $existing->status, 'full_access' => $existing->full_access, 'permissions' => $existing->permissions] : [];

            $attributes = [
                'full_access' => $data['full_access'],
                'permissions' => $data['permissions'],
                'token' => Str::random(48),
                'status' => 'pending',
                'invited_by' => $request->user()?->id,
                'expires_at' => $data['expires_at'] ?? now()->addDays(7),
                'accepted_at' => null,
            ];

            if ($existing) {
                $existing->forceFill($attributes)->save();
                $invitation = $existing;
            } else {
                $invitation = CompanyInvitation::query()->create(array_merge([
                    'company_id' => $this->tenantContext->companyId(),
                    'email' => $data['email'],
                ], $attributes));
            }
            $this->audit('access.invitation.created', $invitation, $before);

            return $invitation;
        });

        return $this->successResponse($invitation->refresh(), 'Invitation created.', 201);
    }

    public function resend(Request $request, int $id): JsonResponse
    {
        $invitation = DB::transaction(function () use ($request, $id) {
            Company::query()->whereKey($this->tenantContext->companyId())->lockForUpdate()->firstOrFail();
            $invitation = $this->invitation($id);
            if ($invitation->status === 'accepted') {
                throw ApiException::make('INVITATION_ALREADY_ACCEPTED', 'Accepted invitation cannot be resent.', 422);
            }
            $before = ['status' => $invitation->status, 'full_access' => $invitation->full_access, 'permissions' => $invitation->permissions];
            $invitation->forceFill(['token' => Str::random(48), 'status' => 'pending', 'invited_by' => $request->user()?->id, 'expires_at' => now()->addDays(7)])->save();
            $this->audit('access.invitation.resent', $invitation, $before);

            return $invitation;
        });

        return $this->successResponse($invitation, 'Invitation resent.');
    }

    public function revoke(int $id): JsonResponse
    {
        $invitation = DB::transaction(function () use ($id) {
            Company::query()->whereKey($this->tenantContext->companyId())->lockForUpdate()->firstOrFail();
            $invitation = $this->invitation($id);
            if ($invitation->status === 'accepted') {
                throw ApiException::make('INVITATION_ALREADY_ACCEPTED', 'Accepted invitation cannot be revoked.', 422);
            }
            $before = ['status' => $invitation->status, 'full_access' => $invitation->full_access, 'permissions' => $invitation->permissions];
            $invitation->forceFill(['status' => 'revoked'])->save();
            $this->audit('access.invitation.revoked', $invitation, $before);

            return $invitation;
        });

        return $this->successResponse($invitation, 'Invitation revoked.');
    }

    private function invitation(int $id): CompanyInvitation
    {
        return CompanyInvitation::query()
            ->where('company_id', $this->tenantContext->companyId())
            ->whereKey($id)
            ->firstOrFail();
    }

    private function audit(string $event, CompanyInvitation $invitation, array $before = []): void
    {
        $logged = $this->auditLogService->logSuccess([
            'event' => $event,
            'module' => 'access',
            'action' => $event,
            'message' => 'Company invitation changed.',
            'record_type' => CompanyInvitation::class,
            'record_id' => $invitation->id,
            'record_number' => $invitation->email,
            'old_values' => $before,
            'new_values' => ['status' => $invitation->status, 'full_access' => $invitation->full_access, 'permissions' => $invitation->permissions],
            'metadata' => ['status' => $invitation->status],
        ], tenant: false);
        if (! $logged) {
            throw new \RuntimeException('Unable to record access audit.');
        }
    }
}
