<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAdjustment extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'stock_adjustments';
    protected $guarded = [];
    protected $casts = [
        'adjustment_date' => 'date',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function lines(): HasMany { return $this->hasMany(StockAdjustmentLine::class, 'stock_adjustment_id')->orderBy('sort_order'); }
    public function stockMovement(): BelongsTo { return $this->belongsTo(StockMovement::class, 'stock_movement_id'); }
}

