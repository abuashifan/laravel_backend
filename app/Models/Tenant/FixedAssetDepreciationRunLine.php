<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetDepreciationRunLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'fixed_asset_depreciation_run_lines';
    protected $guarded = [];
    protected $casts = [
        'metadata' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FixedAssetDepreciationRun::class, 'fixed_asset_depreciation_run_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FixedAssetDepreciationSchedule::class, 'fixed_asset_depreciation_schedule_id');
    }
}
