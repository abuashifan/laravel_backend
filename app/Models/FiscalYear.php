<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'year',
        'start_date',
        'end_date',
        'status',
        'is_active',
        'closing_required_at',
        'closing_started_at',
        'closed_at',
        'closed_by',
        'metadata',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'closing_required_at' => 'datetime',
        'closing_started_at' => 'datetime',
        'closed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosingRequired(): bool
    {
        return $this->status === 'closing_required';
    }

    public function isClosingInProgress(): bool
    {
        return $this->status === 'closing_in_progress';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function containsDate(string|\DateTimeInterface $date): bool
    {
        $d = Carbon::parse($date)->startOfDay();
        return $d->betweenIncluded(Carbon::parse($this->start_date), Carbon::parse($this->end_date));
    }
}

