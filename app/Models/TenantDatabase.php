<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDatabase extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'database_name',
        'database_path',
        'driver',
        'status',
        'migration_version',
        'last_migrated_at',
        'last_backup_at',
        'size_bytes',
        'metadata',
    ];

    protected $casts = [
        'last_migrated_at' => 'datetime',
        'last_backup_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

