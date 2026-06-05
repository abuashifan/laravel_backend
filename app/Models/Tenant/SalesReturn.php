<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'sales_returns';
    protected $guarded = [];
    protected $casts = ['return_date' => 'date', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'voided_at' => 'datetime', 'metadata' => 'array'];

    public function lines(): HasMany { return $this->hasMany(SalesReturnLine::class, 'sales_return_id')->orderBy('sort_order'); }
    public function customer(): BelongsTo { return $this->belongsTo(Contact::class, 'customer_id'); }
    public function salesInvoice(): BelongsTo { return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id'); }
    public function deliveryOrder(): BelongsTo { return $this->belongsTo(DeliveryOrder::class, 'delivery_order_id'); }
}
