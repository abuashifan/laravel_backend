<?php

namespace Tests\Feature\Sales;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Models\User;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class SalesTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUpTenant(string $role = 'owner'): array
    {
        $user = User::factory()->create(['status' => 'active']);

        $company = Company::query()->create([
            'name' => 'Company Sales',
            'slug' => 'company-sales-'.$user->id,
            'code' => 'CMP-SALES-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $tenantPath = database_path('tenants/test_sales_'.$company->id.'_'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath));
        File::put($tenantPath, '');

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => basename($tenantPath),
            'database_path' => $tenantPath,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        app(TenantConnectionManager::class)->connect($tenantPath);

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        Sanctum::actingAs($user);

        return [
            'user' => $user,
            'company' => $company,
            'headers' => ['X-Company-ID' => (string) $company->id],
            'tenant_path' => $tenantPath,
        ];
    }

    protected function createCustomer(array $attributes = []): int
    {
        return (int) \App\Models\Tenant\Contact::query()->create(array_merge([
            'name' => 'Customer A',
            'contact_type' => 'customer',
            'is_customer' => true,
            'is_active' => true,
        ], $attributes))->id;
    }

    protected function quotationPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $this->createCustomer(),
            'quotation_date' => '2026-05-20',
            'valid_until' => '2026-05-30',
            'is_taxable' => true,
            'tax_included' => false,
            'header_discount_type' => null,
            'header_discount_value' => null,
            'lines' => [
                [
                    'description' => 'Implementation service',
                    'quantity' => 2,
                    'unit_price' => 100,
                    'discount_type' => null,
                    'discount_value' => null,
                    'tax_rate' => 11,
                ],
            ],
        ], $overrides);
    }
}
