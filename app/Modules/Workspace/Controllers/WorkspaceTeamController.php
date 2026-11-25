<?php

namespace App\Modules\Workspace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workspace\Requests\UpdateWorkspaceBudgetAccessRequest;
use App\Modules\Workspace\Requests\WorkspaceInvitationRequest;
use App\Modules\Workspace\Services\WorkspaceBudgetAccessService;
use App\Modules\Workspace\Support\BudgetPermission;
use App\Modules\Workspace\Services\WorkspaceInvitationService;
use App\Shared\Api\ApiResponse;
use App\Shared\Models\CompanyInvitation;
use App\Shared\Models\TenantDatabase;
use App\Shared\Permission\PermissionService;
use App\Shared\Permission\WorkspaceMemberAccess;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;

class WorkspaceTeamController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $context, private readonly WorkspaceInvitationService $invitations) {}

    public function index(WorkspaceMemberAccess $members, PermissionService $permissions): JsonResponse
    {
        $canManageBudget = $permissions->can(BudgetPermission::MANAGE_ACCESS);
        $people = $members->members($this->context->company())->with('user:id,name,email')
            ->when($canManageBudget, fn ($query) => $query->with('assignedPermissions:id,key'))->orderBy('id')->get()
            ->map(function ($member) use ($canManageBudget) {
                $person = ['id' => $member->id, 'name' => $member->user->name, 'email' => $member->user->email];
                if ($canManageBudget && ! $member->full_access && ! $member->is_owner) {
                    $person['budget_permissions'] = $member->assignedPermissions->pluck('key')
                        ->intersect(BudgetPermission::GRANTABLE)->values()->all();
                }

                return $person;
            });
        $pending = $permissions->can('workspace.members.manage')
            ? CompanyInvitation::query()->where('company_id', $this->context->companyId())
                ->where('product', TenantDatabase::PRODUCT_WORKSPACE)->where('status', 'pending')->where('expires_at', '>', now())
                ->latest('id')->get(['id', 'email', 'expires_at'])
            : [];

        return $this->successResponse(['members' => $people, 'invitations' => $pending]);
    }

    public function updateBudgetAccess(UpdateWorkspaceBudgetAccessRequest $request, int $member, WorkspaceBudgetAccessService $access): JsonResponse
    {
        return $this->successResponse($access->update($member, $request->validated('permissions'), $request->user()->id));
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
