<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingInvoiceLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'billing_invoice_lines';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function billingInvoice(): BelongsTo { return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id'); }
    public function salesInvoiceLine(): BelongsTo { return $this->belongsTo(SalesInvoiceLine::class, 'sales_invoice_line_id'); }
}
