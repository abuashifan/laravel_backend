<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpnameLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'stock_opname_lines';
    protected $guarded = [];
    protected $casts = [
        'metadata' => 'array',
        'system_quantity' => 'decimal:4',
        'physical_quantity' => 'decimal:4',
        'difference_quantity' => 'decimal:4',
        'average_cost' => 'decimal:6',
        'difference_value' => 'decimal:2',
        'counted_at' => 'datetime',
    ];

    public function opname(): BelongsTo { return $this->belongsTo(StockOpname::class, 'stock_opname_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }
}

