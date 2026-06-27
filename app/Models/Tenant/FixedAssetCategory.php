<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'asset_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'accumulated_depreciation_account_id');
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'depreciation_expense_account_id');
    }

    public function clearingAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'clearing_account_id');
    }

    public function disposalGainAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'disposal_gain_account_id');
    }

    public function disposalLossAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'disposal_loss_account_id');
    }
}
