<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountMapping extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'account_mappings';

    protected $fillable = [
        'mapping_key',
        'module',
        'account_id',
        'is_required',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function isRequired(): bool
    {
        return (bool) $this->is_required;
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}

