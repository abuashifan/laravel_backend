<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodEndRun extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'period_end_runs';
    protected $guarded = [];
    protected $casts = [
        'checklist_snapshot' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function routines(): HasMany
    {
        return $this->hasMany(PeriodEndRunRoutine::class, 'period_end_run_id');
    }
}
