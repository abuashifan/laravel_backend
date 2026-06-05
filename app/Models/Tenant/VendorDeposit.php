<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorDeposit extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'vendor_deposits';
    protected $guarded = [];
    protected $casts = [
        'deposit_date' => 'date',
        'metadata' => 'array',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Contact::class, 'vendor_id'); }
}
