<?php

namespace App\Http\Controllers\Api\Companies;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
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

