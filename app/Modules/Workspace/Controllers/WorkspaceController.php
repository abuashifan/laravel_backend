<?php

namespace App\Modules\Workspace\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\ApiResponse;
use App\Shared\Budget\BudgetSourceService;
use App\Shared\Auth\TokenAbility;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Permission\PermissionService;
use App\Shared\Subscription\PlanOwnerResolver;
use App\Shared\Subscription\SubscriptionService;
use App\Shared\Tenant\ProductActivationService;
use App\Shared\Tenant\TenantContext;
use App\Shared\Tenant\TenantProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    use ApiResponse;

    public function status(Request $request, ProductActivationService $activation, TenantProductService $products, PlanOwnerResolver $owners, SubscriptionService $subscriptions): JsonResponse
    {
        $user = $request->user();
        $company = Company::query()->find($request->header('X-Company-ID'));
        $member = $company && $user->tokenCan(TokenAbility::CLIENT) ? $activation->membership($user, $company) : null;
        if (! $member) {
            return $this->errorCodeResponse(ApiErrorCode::COMPANY_ACCESS_DENIED, null, [], 403);
        }

        $owner = $owners->ownerOf($company);

        return $this->successResponse([
            'active' => $products->find($company, TenantDatabase::PRODUCT_WORKSPACE)?->status === 'active',
            'can_activate' => $activation->canActivate($member),
            'subscribed' => $owner && $subscriptions->hasAccess($owner, TenantDatabase::PRODUCT_WORKSPACE),
        ]);
    }

    public function bootstrap(TenantContext $context, PermissionService $permissions, PlanOwnerResolver $owners, SubscriptionService $subscriptions, BudgetSourceService $sources): JsonResponse
    {
        $company = $context->company();
        $owner = $owners->ownerOf($company);

        $today = today()->toDateString();
        $workspaceBudget = $sources->periods($company->id)->whereDate('period_from', '<=', $today)->whereDate('period_to', '>=', $today)->exists();

        return $this->successResponse([
            'company' => ['id' => $company->id, 'name' => $company->name],
            'permissions' => array_values(array_filter($permissions->userPermissions(), fn (string $key) => str_starts_with($key, 'workspace.'))),
            'read_only' => ! $owner || ! $subscriptions->hasAccess($owner, TenantDatabase::PRODUCT_WORKSPACE),
            'budget_source' => $workspaceBudget ? 'workspace' : 'accounting',
        ]);
    }
}
