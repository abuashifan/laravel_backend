<?php

namespace App\Http\Controllers\Api\Companies;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $companies = $user->companies()
            ->wherePivot('status', 'active')
            ->with('tenantDatabase')
            ->get()
            ->map(function (Company $company) {
                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'legal_name' => $company->legal_name,
                    'slug' => $company->slug,
                    'code' => $company->code,
                    'status' => $company->status,
                    'user_role' => $company->pivot?->role,
                    'tenant_database' => $company->tenantDatabase ? [
                        'database_name' => $company->tenantDatabase->database_name,
                        'status' => $company->tenantDatabase->status,
                    ] : null,
                ];
            })
            ->values();

        return $this->successResponse($companies, 'Companies retrieved successfully');
    }

    public function select(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $user = $request->user();

        $companyUser = CompanyUser::query()
            ->where('company_id', $data['company_id'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (!$companyUser) {
            return $this->errorResponse('Anda tidak punya akses ke company ini.', 403);
        }

        $company = Company::query()
            ->with('tenantDatabase')
            ->findOrFail($data['company_id']);

        $tenantDatabase = $company->tenantDatabase;

        if (!$tenantDatabase || $tenantDatabase->status !== 'active') {
            return $this->errorResponse('Tenant database company belum aktif.', 422);
        }

        $activeCompany = [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'slug' => $company->slug,
            'code' => $company->code,
            'user_role' => $companyUser->role,
            'tenant_database' => [
                'database_name' => $tenantDatabase->database_name,
                'database_path' => $tenantDatabase->database_path,
                'status' => $tenantDatabase->status,
            ],
        ];

        return $this->successResponse([
            'active_company' => $activeCompany,
        ], 'Company selected successfully');
    }
}

