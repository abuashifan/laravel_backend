<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentNumberSequence extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_type',
        'fiscal_year_id',
        'period_key',
        'last_number',
        'metadata',
    ];

    protected $casts = [
        'last_number' => 'integer',
        'metadata' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}

