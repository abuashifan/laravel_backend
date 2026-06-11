<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovementLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'stock_movement_lines';
    protected $guarded = [];
    protected $casts = [
        'metadata' => 'array',
        'inventory_account_id' => 'integer',
        'quantity' => 'decimal:4',
        'quantity_before' => 'decimal:4',
        'quantity_after' => 'decimal:4',
        'average_cost_before' => 'decimal:6',
        'average_cost_after' => 'decimal:6',
        'value_before' => 'decimal:2',
        'value_after' => 'decimal:2',
        'unit_cost' => 'decimal:6',
        'total_cost' => 'decimal:2',
    ];

    public function stockMovement(): BelongsTo { return $this->belongsTo(StockMovement::class, 'stock_movement_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function inventoryAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'inventory_account_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class, 'department_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class, 'project_id'); }
}
