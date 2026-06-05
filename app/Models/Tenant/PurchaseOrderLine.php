<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'purchase_order_lines';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function purchaseRequestLine(): BelongsTo { return $this->belongsTo(PurchaseRequestLine::class, 'purchase_request_line_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }
}
