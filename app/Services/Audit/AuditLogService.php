<?php

namespace App\Services\Audit;

use App\Models\ActivityLog;
use App\Models\Tenant\TenantAuditLog;
use App\Services\Tenant\TenantContext;
use App\Support\Audit\AuditResult;
use Illuminate\Http\Request;
use Throwable;

class AuditLogService
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function logSuccess(array $data, bool $tenant = true): mixed
    {
        $data['result'] = AuditResult::SUCCESS;
        return $tenant ? $this->logTenant($data) : $this->logCentral($data);
    }

    public function logFailed(array $data, bool $tenant = true): mixed
    {
        $data['result'] = AuditResult::FAILED;
        return $tenant ? $this->logTenant($data) : $this->logCentral($data);
    }

    public function logDenied(array $data, bool $tenant = true): mixed
    {
        $data['result'] = AuditResult::DENIED;
        return $tenant ? $this->logTenant($data) : $this->logCentral($data);
    }

    public function logWarning(array $data, bool $tenant = true): mixed
    {
        $data['result'] = AuditResult::WARNING;
        return $tenant ? $this->logTenant($data) : $this->logCentral($data);
    }

    public function logTenant(array $data): ?TenantAuditLog
    {
        try {
            $data = $this->normalizeData($data);
            $data = $this->withRequestContext($data);
            $data = $this->withTenantContext($data);

            return TenantAuditLog::query()->create($data);
        } catch (Throwable $e) {
            return null;
        }
    }

    public function logCentral(array $data): mixed
    {
        try {
            $data = $this->normalizeData($data);
            $data = $this->withRequestContext($data);
            $data = $this->withTenantContext($data);

            return ActivityLog::query()->create([
                'user_id' => $data['user_id'] ?? null,
                'company_id' => $data['company_id'] ?? null,
                'action' => $data['action'] ?? ($data['event'] ?? null),
                'module' => $data['module'] ?? null,
                'description' => $data['message'] ?? null,
                'subject_type' => $data['record_type'] ?? null,
                'subject_id' => $data['record_id'] ?? null,
                'properties' => [
                    'event' => $data['event'] ?? null,
                    'result' => $data['result'] ?? null,
                    'record_number' => $data['record_number'] ?? null,
                    'source' => [
                        'source_type' => $data['source_type'] ?? null,
                        'source_id' => $data['source_id'] ?? null,
                        'source_number' => $data['source_number'] ?? null,
                        'source_revision' => $data['source_revision'] ?? null,
                        'source_module' => $data['source_module'] ?? null,
                        'source_batch_id' => $data['source_batch_id'] ?? null,
                    ],
                    'revision_id' => $data['revision_id'] ?? null,
                    'old_values' => $data['old_values'] ?? null,
                    'new_values' => $data['new_values'] ?? null,
                    'metadata' => $data['metadata'] ?? null,
                    'ip_address' => $data['ip_address'] ?? null,
                    'user_agent' => $data['user_agent'] ?? null,
                ],
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
            ]);
        } catch (Throwable $e) {
            return null;
        }
    }

    public function withRequestContext(array $data): array
    {
        try {
            if (! app()->bound('request')) {
                return $data;
            }

            /** @var Request $request */
            $request = app('request');

            $data['ip_address'] ??= $request->ip();
            $data['user_agent'] ??= $request->userAgent();
        } catch (Throwable $e) {
            // ignore
        }

        return $data;
    }

    public function normalizeData(array $data): array
    {
        if (isset($data['record_id']) && $data['record_id'] !== null) {
            $data['record_id'] = (string) $data['record_id'];
        }

        if (isset($data['source_id']) && $data['source_id'] !== null) {
            $data['source_id'] = (string) $data['source_id'];
        }

        $data['old_values'] = isset($data['old_values']) ? (array) $data['old_values'] : null;
        $data['new_values'] = isset($data['new_values']) ? (array) $data['new_values'] : null;
        $data['metadata'] = isset($data['metadata']) ? (array) $data['metadata'] : null;

        return $data;
    }

    private function withTenantContext(array $data): array
    {
        try {
            $company = $this->tenantContext->company();
            if ($company) {
                $data['company_id'] ??= $company->id;
            }

            $companyUser = $this->tenantContext->companyUser();
            if ($companyUser) {
                $data['user_id'] ??= $companyUser->user_id;
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $data;
    }
}
