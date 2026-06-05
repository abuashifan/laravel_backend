<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryOrderLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'delivery_order_lines';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function deliveryOrder(): BelongsTo { return $this->belongsTo(DeliveryOrder::class, 'delivery_order_id'); }
    public function salesOrderLine(): BelongsTo { return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class, 'department_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class, 'project_id'); }
}
