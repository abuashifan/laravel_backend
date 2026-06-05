<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReturn extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'purchase_returns';
    protected $guarded = [];
    protected $casts = ['return_date' => 'date', 'metadata' => 'array', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'voided_at' => 'datetime'];

    public function lines(): HasMany { return $this->hasMany(PurchaseReturnLine::class, 'purchase_return_id')->orderBy('sort_order'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Contact::class, 'vendor_id'); }
    public function vendorBill(): BelongsTo { return $this->belongsTo(VendorBill::class, 'vendor_bill_id'); }
    public function goodsReceipt(): BelongsTo { return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id'); }
}
