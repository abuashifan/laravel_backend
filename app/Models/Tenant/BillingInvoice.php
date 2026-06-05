<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingInvoice extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'billing_invoices';
    protected $guarded = [];
    protected $casts = ['billing_date' => 'date', 'due_date' => 'date', 'issued_at' => 'datetime', 'cancelled_at' => 'datetime', 'metadata' => 'array'];

    public function lines(): HasMany { return $this->hasMany(BillingInvoiceLine::class, 'billing_invoice_id')->orderBy('sort_order'); }
    public function customer(): BelongsTo { return $this->belongsTo(Contact::class, 'customer_id'); }
    public function salesInvoice(): BelongsTo { return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id'); }
    public function paymentTerm(): BelongsTo { return $this->belongsTo(PaymentTerm::class, 'payment_term_id'); }
}
