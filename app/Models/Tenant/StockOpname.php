<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOpname extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'stock_opnames';
    protected $guarded = [];
    protected $casts = [
        'opname_date' => 'date',
        'counted_at' => 'datetime',
        'finalized_at' => 'datetime',
        'voided_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function lines(): HasMany { return $this->hasMany(StockOpnameLine::class, 'stock_opname_id')->orderBy('sort_order'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function stockMovement(): BelongsTo { return $this->belongsTo(StockMovement::class, 'stock_movement_id'); }
}

