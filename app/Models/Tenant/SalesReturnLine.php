<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'sales_return_lines';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function salesReturn(): BelongsTo { return $this->belongsTo(SalesReturn::class, 'sales_return_id'); }
    public function salesInvoiceLine(): BelongsTo { return $this->belongsTo(SalesInvoiceLine::class, 'sales_invoice_line_id'); }
    public function deliveryOrderLine(): BelongsTo { return $this->belongsTo(DeliveryOrderLine::class, 'delivery_order_line_id'); }
}
