<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'sales_invoice_lines';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'revenue_account_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }

    public function deliveryOrderLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrderLine::class, 'delivery_order_line_id');
    }

    public function proformaInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoiceLine::class, 'proforma_invoice_line_id');
    }
}
