<?php

namespace App\Modules\Workspace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workspace\Requests\WorkspaceInvitationRequest;
use App\Modules\Workspace\Services\WorkspaceInvitationService;
use App\Shared\Api\ApiResponse;
use App\Shared\Models\CompanyInvitation;
use App\Shared\Models\TenantDatabase;
use App\Shared\Permission\WorkspaceMemberAccess;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;

class WorkspaceTeamController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $context, private readonly WorkspaceInvitationService $invitations) {}

    public function index(WorkspaceMemberAccess $members): JsonResponse
    {
        $people = $members->members($this->context->company())->with('user:id,name,email')->orderBy('id')->get()
            ->map(fn ($member) => ['id' => $member->id, 'name' => $member->user->name, 'email' => $member->user->email]);
        $pending = CompanyInvitation::query()->where('company_id', $this->context->companyId())
            ->where('product', TenantDatabase::PRODUCT_WORKSPACE)->where('status', 'pending')->where('expires_at', '>', now())
            ->latest('id')->get(['id', 'email', 'expires_at']);

        return $this->successResponse(['members' => $people, 'invitations' => $pending]);
    }

    public function invite(WorkspaceInvitationRequest $request): JsonResponse
    {
        $delivery = $this->invitations->invite($this->context->company(), $request->user(), $request->validated('email'));

        return $this->successResponse($delivery, 'Undangan dibuat.', 201);
    }

    public function revoke(int $invitation): JsonResponse
    {
        $this->invitations->revoke($this->context->company(), $invitation);

        return $this->successResponse(null, 'Undangan dibatalkan.');
    }
}
