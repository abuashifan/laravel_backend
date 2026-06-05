<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'goods_receipts';
    protected $guarded = [];
    protected $casts = [
        'receipt_date' => 'date',
        'metadata' => 'array',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function lines(): HasMany { return $this->hasMany(GoodsReceiptLine::class, 'goods_receipt_id')->orderBy('sort_order'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Contact::class, 'vendor_id'); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
}
