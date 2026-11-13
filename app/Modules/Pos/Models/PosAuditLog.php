<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

/** Baris log audit toko. Hanya ditambah lewat `PosAuditService::record()`. */
class PosAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'pos';

    protected $table = 'pos_audit_logs';

    protected $fillable = [
        'action', 'user_id', 'user_name', 'subject_type', 'subject_id', 'subject_label',
        'summary', 'changes', 'reason', 'approved_with_pin',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'subject_id' => 'integer',
        'changes' => 'array',
        'approved_with_pin' => 'boolean',
        'created_at' => 'datetime',
    ];
}
