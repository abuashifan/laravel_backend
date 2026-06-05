<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'purchase_return_lines';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function purchaseReturn(): BelongsTo { return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id'); }
    public function vendorBillLine(): BelongsTo { return $this->belongsTo(VendorBillLine::class, 'vendor_bill_line_id'); }
    public function goodsReceiptLine(): BelongsTo { return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id'); }
}
