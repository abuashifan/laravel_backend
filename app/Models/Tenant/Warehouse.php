<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'warehouses';

    protected $fillable = [
        'code',
        'name',
        'address',
        'is_default',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_default' => 'boolean',
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

    public function isDefault(): bool
    {
        return (bool) $this->is_default;
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}

