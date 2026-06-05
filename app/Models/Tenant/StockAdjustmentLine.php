<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustmentLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'stock_adjustment_lines';
    protected $guarded = [];
    protected $casts = [
        'metadata' => 'array',
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:6',
        'total_cost' => 'decimal:2',
        'system_quantity_before' => 'decimal:4',
        'system_value_before' => 'decimal:2',
    ];

    public function adjustment(): BelongsTo { return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class, 'department_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class, 'project_id'); }
}

