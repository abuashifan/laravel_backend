<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetDisposal extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'fixed_asset_disposals';
    protected $guarded = [];
    protected $casts = [
        'disposal_date' => 'date',
        'posted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
