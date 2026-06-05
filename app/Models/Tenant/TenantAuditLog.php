<?php

namespace App\Models\Tenant;

use App\Support\Audit\AuditResult;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantAuditLog extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'tenant_audit_logs';

    protected $fillable = [
        'event',
        'action',
        'module',
        'record_type',
        'record_id',
        'record_number',
        'source_type',
        'source_id',
        'source_number',
        'source_revision',
        'source_module',
        'source_batch_id',
        'revision_id',
        'user_id',
        'company_id',
        'result',
        'message',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'source_revision' => 'integer',
        'revision_id' => 'integer',
        'user_id' => 'integer',
        'company_id' => 'integer',
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
    ];

    public function isSuccess(): bool
    {
        return $this->result === AuditResult::SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->result === AuditResult::FAILED;
    }

    public function isDenied(): bool
    {
        return $this->result === AuditResult::DENIED;
    }

    public function isWarning(): bool
    {
        return $this->result === AuditResult::WARNING;
    }
}

