<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockMovement extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'stock_movements';
    protected $guarded = [];
    protected $casts = [
        'movement_date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'metadata' => 'array',
        'total_quantity' => 'decimal:4',
        'total_value' => 'decimal:2',
    ];

    public function lines(): HasMany { return $this->hasMany(StockMovementLine::class, 'stock_movement_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function reversalOf(): BelongsTo { return $this->belongsTo(self::class, 'reversal_of_id'); }
    public function reversedBy(): BelongsTo { return $this->belongsTo(self::class, 'reversed_by_id'); }
}

