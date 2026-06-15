<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAsset extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'fixed_assets';
    protected $guarded = [];
    protected $casts = [
        'acquisition_date' => 'date',
        'service_start_date' => 'date',
        'capitalized_at' => 'datetime',
        'disposed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(FixedAssetCategory::class, 'fixed_asset_category_id');
    }

    public function acquisitions(): HasMany
    {
        return $this->hasMany(FixedAssetAcquisition::class, 'fixed_asset_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciationSchedule::class, 'fixed_asset_id')->orderBy('period');
    }

    public function disposals(): HasMany
    {
        return $this->hasMany(FixedAssetDisposal::class, 'fixed_asset_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FixedAssetTransaction::class, 'fixed_asset_id')->orderByDesc('transaction_date')->orderByDesc('id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
