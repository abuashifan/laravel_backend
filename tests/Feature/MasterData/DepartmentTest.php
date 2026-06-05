<?php

namespace Tests\Feature\MasterData;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class DepartmentTest extends MasterDataTestCase
{
    public function test_unauthenticated_cannot_list_departments(): void
    {
        $res = $this->getJson('/api/master-data/departments');
        $res->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'owner');

        $res = $this->getJson('/api/master-data/departments');
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'X_COMPANY_ID_REQUIRED');
    }

    public function test_create_update_deactivate_and_duplicate_code_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $dept = $this->postJson('/api/master-data/departments', [
            'code' => 'OPS',
            'name' => 'Operational',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->postJson('/api/master-data/departments', [
            'code' => 'OPS',
            'name' => 'Operational 2',
        ], $ctx['headers'])->assertStatus(422);

        $this->patchJson('/api/master-data/departments/'.$dept['id'], [
            'name' => 'Ops Updated',
        ], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.name', 'Ops Updated');

        $this->patchJson('/api/master-data/departments/'.$dept['id'].'/deactivate', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $activeOnly = $this->getJson('/api/master-data/departments?is_active=1', $ctx['headers'])->assertStatus(200);
        $this->assertCount(0, $activeOnly->json('data'));
    }

    public function test_user_without_permission_cannot_create_department(): void
    {
        $ctx = $this->setUpTenant(role: 'viewer');

        $res = $this->postJson('/api/master-data/departments', [
            'code' => 'FIN',
            'name' => 'Finance',
        ], $ctx['headers']);

        $res->assertStatus(403);
        $res->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_user_cannot_access_another_company_tenant_department(): void
    {
        $ctx1 = $this->setUpTenant(role: 'owner');

        $created = $this->postJson('/api/master-data/departments', [
            'code' => 'HR',
            'name' => 'Human Resource',
        ], $ctx1['headers'])->assertStatus(201);

        $deptId = (int) $created->json('data.id');

        $user = $ctx1['user'];
        $company2 = Company::query()->create([
            'name' => 'Company 2',
            'slug' => 'company-2-'.$user->id,
            'code' => 'CMP-'.str_pad((string) ($user->id + 1), 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        CompanyUser::query()->create([
            'company_id' => $company2->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $tenantPath2 = database_path('tenants/test_company_'.$company2->id.'_'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath2));
        File::put($tenantPath2, '');

        TenantDatabase::query()->create([
            'company_id' => $company2->id,
            'database_name' => basename($tenantPath2),
            'database_path' => $tenantPath2,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        app(TenantConnectionManager::class)->connect($tenantPath2);
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        $res = $this->getJson('/api/master-data/departments/'.$deptId, ['X-Company-ID' => (string) $company2->id]);
        $res->assertStatus(404);

        $this->getJson('/api/master-data/departments/'.$deptId, $ctx1['headers'])->assertStatus(200);
    }
}

