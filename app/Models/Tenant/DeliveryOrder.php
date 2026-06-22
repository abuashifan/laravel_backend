<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryOrder extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'delivery_orders';

    protected $guarded = [];

    protected $casts = ['delivery_date' => 'date', 'metadata' => 'array', 'ready_at' => 'datetime', 'shipped_at' => 'datetime', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime', 'voided_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryOrderLine::class, 'delivery_order_id')->orderBy('sort_order');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
