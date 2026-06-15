<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAssetCategory extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'fixed_asset_categories';
    protected $guarded = [];
    protected $casts = [
        'default_useful_life_years' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'fixed_asset_category_id');
    }
}
