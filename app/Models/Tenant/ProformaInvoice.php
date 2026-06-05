<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProformaInvoice extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'proforma_invoices';
    protected $guarded = [];
    protected $casts = ['proforma_date' => 'date', 'valid_until' => 'date', 'is_taxable' => 'boolean', 'tax_included' => 'boolean', 'metadata' => 'array', 'issued_at' => 'datetime', 'accepted_at' => 'datetime', 'cancelled_at' => 'datetime', 'converted_at' => 'datetime'];

    public function lines(): HasMany { return $this->hasMany(ProformaInvoiceLine::class, 'proforma_invoice_id')->orderBy('sort_order'); }
    public function customer(): BelongsTo { return $this->belongsTo(Contact::class, 'customer_id'); }
    public function quotation(): BelongsTo { return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id'); }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
}
