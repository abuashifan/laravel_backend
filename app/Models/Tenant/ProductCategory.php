<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'product_categories';

    protected $fillable = [
        'name',
        'parent_category_id',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'parent_category_id' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_category_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_category_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'product_category_id');
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

