<?php

namespace App\Http\Controllers\Api\Access;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\InviteCompanyUserRequest;
use App\Models\CompanyInvitation;
use App\Models\Role;
use App\Services\Audit\AuditLogService;
use App\Services\Tenant\TenantContext;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CompanyInvitationAccessController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function index(): JsonResponse
    {
        $invitations = CompanyInvitation::query()
            ->where('company_id', $this->tenantContext->companyId())
            ->orderByDesc('created_at')
            ->get()
            ->each(function (CompanyInvitation $invitation): void {
                if ($invitation->status === 'pending' && $invitation->expires_at?->isPast()) {
                    $invitation->forceFill(['status' => 'expired'])->save();
                }
            });

        return $this->successResponse($invitations, 'Invitations retrieved.');
    }

    public function store(InviteCompanyUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $existing = CompanyInvitation::query()
            ->where('company_id', $this->tenantContext->companyId())
            ->where('email', $data['email'])
            ->first();

        if ($existing && $existing->status === 'pending' && ! $existing->expires_at?->isPast()) {
            throw ApiException::make('ACTIVE_INVITATION_EXISTS', 'An active invitation already exists for this email.', 422);
        }

        $role = isset($data['role_id']) && $data['role_id']
            ? $this->role((int) $data['role_id'])
            : $this->roleBySlug((string) ($data['role'] ?? 'viewer'));
        $attributes = [
            'role' => $role->slug,
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
        $this->audit('access.invitation.created', $invitation);

        return $this->successResponse($invitation->refresh(), 'Invitation created.', 201);
    }

    public function resend(Request $request, int $id): JsonResponse
    {
        $invitation = $this->invitation($id);
        if ($invitation->status === 'accepted') {
            throw ApiException::make('INVITATION_ALREADY_ACCEPTED', 'Accepted invitation cannot be resent.', 422);
        }

        $invitation->forceFill([
            'token' => Str::random(48),
            'status' => 'pending',
            'invited_by' => $request->user()?->id,
            'expires_at' => now()->addDays(7),
        ])->save();
        $this->audit('access.invitation.resent', $invitation);

        return $this->successResponse($invitation, 'Invitation resent.');
    }

    public function revoke(int $id): JsonResponse
    {
        $invitation = $this->invitation($id);
        if ($invitation->status === 'accepted') {
            throw ApiException::make('INVITATION_ALREADY_ACCEPTED', 'Accepted invitation cannot be revoked.', 422);
        }
        $invitation->forceFill(['status' => 'revoked'])->save();
        $this->audit('access.invitation.revoked', $invitation);

        return $this->successResponse($invitation, 'Invitation revoked.');
    }

    private function invitation(int $id): CompanyInvitation
    {
        return CompanyInvitation::query()
            ->where('company_id', $this->tenantContext->companyId())
            ->whereKey($id)
            ->firstOrFail();
    }

    private function role(int $roleId): Role
    {
        return Role::query()
            ->where(function (Builder $query): void {
                $query->where('is_system', true)->orWhere('company_id', $this->tenantContext->companyId());
            })
            ->where('is_active', true)
            ->whereKey($roleId)
            ->firstOrFail();
    }

    private function roleBySlug(string $slug): Role
    {
        return Role::query()
            ->where(function (Builder $query): void {
                $query->where('is_system', true)->orWhere('company_id', $this->tenantContext->companyId());
            })
            ->where('is_active', true)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function audit(string $event, CompanyInvitation $invitation): void
    {
        $this->auditLogService->logSuccess([
            'event' => $event,
            'module' => 'access',
            'action' => $event,
            'message' => 'Company invitation changed.',
            'record_type' => CompanyInvitation::class,
            'record_id' => $invitation->id,
            'record_number' => $invitation->email,
            'metadata' => ['status' => $invitation->status],
        ], tenant: false);
    }
}
