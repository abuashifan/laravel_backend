<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'stock_balances';
    protected $guarded = [];
    protected $casts = [
        'metadata' => 'array',
        'quantity_on_hand' => 'decimal:4',
        'quantity_reserved' => 'decimal:4',
        'quantity_available' => 'decimal:4',
        'average_cost' => 'decimal:6',
        'total_value' => 'decimal:2',
        'last_movement_at' => 'datetime',
    ];

    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function lastMovement(): BelongsTo { return $this->belongsTo(StockMovement::class, 'last_movement_id'); }

    public function recalculateAvailable(): void
    {
        $this->quantity_available = (float) $this->quantity_on_hand - (float) $this->quantity_reserved;
    }

    public function isNegative(): bool
    {
        return (float) $this->quantity_on_hand < 0;
    }

    public function hasStock(): bool
    {
        return (float) $this->quantity_on_hand > 0;
    }
}

