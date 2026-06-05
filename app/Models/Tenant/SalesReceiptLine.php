<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReceiptLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'sales_receipt_lines';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function salesReceipt(): BelongsTo { return $this->belongsTo(SalesReceipt::class, 'sales_receipt_id'); }
    public function salesInvoice(): BelongsTo { return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id'); }
    public function billingInvoice(): BelongsTo { return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id'); }
}
