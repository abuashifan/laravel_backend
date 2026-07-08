<?php

namespace App\Modules\Companies\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiResponse;
use App\Shared\Models\User;
use Illuminate\Http\JsonResponse;

class MyCompaniesController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $user = User::where('email', 'admin@example.com')->firstOrFail();

        $companies = $user->companies()
            ->with(['tenantDatabase', 'activeSubscription.plan'])
            ->get()
            ->map(function ($company) {
                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'legal_name' => $company->legal_name,
                    'slug' => $company->slug,
                    'code' => $company->code,
                    'status' => $company->status,
                    'role' => $company->pivot?->role,
                    'tenant_database' => $company->tenantDatabase ? [
                        'database_name' => $company->tenantDatabase->database_name,
                        'status' => $company->tenantDatabase->status,
                    ] : null,
                    'subscription' => $company->activeSubscription ? [
                        'status' => $company->activeSubscription->status,
                        'plan' => $company->activeSubscription->plan?->code,
                    ] : null,
                ];
            })
            ->values();

        return $this->successResponse($companies, 'Companies retrieved successfully');
    }
}
