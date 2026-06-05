<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'units';

    protected $fillable = [
        'code',
        'name',
        'precision',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'precision' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'unit_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }
}

