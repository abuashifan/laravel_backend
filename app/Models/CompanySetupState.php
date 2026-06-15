<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySetupState extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'status',
        'current_step',
        'opening_date',
        'completed_steps',
        'validation_errors',
        'last_validated_at',
        'finalized_at',
        'finalized_by',
        'reopened_at',
        'reopened_by',
        'metadata',
    ];

    protected $casts = [
        'opening_date' => 'date',
        'completed_steps' => 'array',
        'validation_errors' => 'array',
        'last_validated_at' => 'datetime',
        'finalized_at' => 'datetime',
        'reopened_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }
}
