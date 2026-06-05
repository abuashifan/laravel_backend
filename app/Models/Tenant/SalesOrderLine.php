<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'sales_order_lines';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function quotationLine(): BelongsTo { return $this->belongsTo(SalesQuotationLine::class, 'quotation_line_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }
}
