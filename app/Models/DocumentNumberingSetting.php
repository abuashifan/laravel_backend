<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentNumberingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_type',
        'name',
        'prefix',
        'format',
        'reset_period',
        'padding',
        'mode',
        'allow_manual_number',
        'allow_duplicate_number',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'padding' => 'integer',
        'allow_manual_number' => 'boolean',
        'allow_duplicate_number' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isAuto(): bool
    {
        return $this->mode === 'auto';
    }

    public function isManual(): bool
    {
        return $this->mode === 'manual';
    }

    public function allowsManualNumber(): bool
    {
        return (bool) $this->allow_manual_number;
    }

    public function allowsDuplicateNumber(): bool
    {
        return (bool) $this->allow_duplicate_number;
    }
}

