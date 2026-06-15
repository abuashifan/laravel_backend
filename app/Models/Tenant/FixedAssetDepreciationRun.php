<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAssetDepreciationRun extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'fixed_asset_depreciation_runs';
    protected $guarded = [];
    protected $casts = [
        'posted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciationRunLine::class, 'fixed_asset_depreciation_run_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
