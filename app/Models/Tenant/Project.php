<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'projects';

    protected $fillable = [
        'code',
        'name',
        'description',
        'start_date',
        'end_date',
        'status',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeUsable($query)
    {
        return $query->where('is_active', true)->where('status', 'active');
    }

    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'project_id');
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function isUsable(): bool
    {
        return $this->isActive() && $this->status === 'active';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isOnHold(): bool
    {
        return $this->status === 'on_hold';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}

