<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'purchase_orders';
    protected $guarded = [];
    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'is_taxable' => 'boolean',
        'tax_included' => 'boolean',
        'has_down_payment' => 'boolean',
        'metadata' => 'array',
        'approved_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function lines(): HasMany { return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id')->orderBy('sort_order'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Contact::class, 'vendor_id'); }
    public function purchaseRequest(): BelongsTo { return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id'); }
    public function deposits(): HasMany { return $this->hasMany(VendorDeposit::class, 'purchase_order_id'); }
}
