<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Tenant\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AuditLogServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.tenant', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }

    public function test_audit_result_contains_expected_values(): void
    {
        $this->assertTrue(AuditResult::exists(AuditResult::SUCCESS));
        $this->assertTrue(AuditResult::exists(AuditResult::DENIED));
    }

    public function test_audit_action_contains_expected_values(): void
    {
        $this->assertTrue(AuditAction::exists(AuditAction::CREATE));
        $this->assertTrue(AuditAction::exists(AuditAction::VOID));
        $this->assertTrue(AuditAction::exists(AuditAction::DENY));
    }

    public function test_audit_event_contains_permission_denied(): void
    {
        $this->assertTrue(AuditEvent::exists(AuditEvent::PERMISSION_DENIED));
    }

    public function test_log_tenant_creates_row_with_source_link_and_revision_id(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Company Audit Test',
            'slug' => 'company-audit-test-'.$user->id,
            'code' => 'CMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $companyUser = CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $tenantDatabase = TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => 'company_'.str_pad((string) $company->id, 6, '0', STR_PAD_LEFT).'.sqlite',
            'database_path' => database_path('tenants/company_'.str_pad((string) $company->id, 6, '0', STR_PAD_LEFT).'.sqlite'),
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        app(TenantContext::class)->set($company, $companyUser, $tenantDatabase);

        $service = $this->app->make(AuditLogService::class);
        $log = $service->logDenied([
            'event' => AuditEvent::PERMISSION_DENIED,
            'action' => AuditAction::DENY,
            'module' => 'sales',
            'message' => 'No permission',
            'source_type' => 'sales_invoice',
            'source_id' => 15,
            'source_number' => 'SI-2026-000015',
            'source_revision' => 2,
            'source_module' => 'sales',
            'revision_id' => 99,
            'old_values' => ['a' => 1],
            'new_values' => ['a' => 2],
            'metadata' => ['permission' => 'sales.void'],
        ], tenant: true);

        $this->assertNotNull($log);
        $this->assertSame(AuditResult::DENIED, $log->result);
        $this->assertSame('SI-2026-000015', $log->source_number);
        $this->assertSame(99, $log->revision_id);
        $this->assertSame(['a' => 1], $log->old_values);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($company->id, $log->company_id);
    }

    public function test_with_request_context_does_not_fail_without_request(): void
    {
        $service = $this->app->make(AuditLogService::class);
        $data = $service->withRequestContext(['event' => AuditEvent::RECORD_VIEWED]);
        $this->assertArrayHasKey('event', $data);
    }
}
